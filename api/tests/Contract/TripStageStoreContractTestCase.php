<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\Enum\AlertGroup;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The behaviour every {@see TripStageStoreInterface} implementation owes its callers.
 *
 * The trips are seeded through {@see TripRequestRepositoryInterface::initializeTrip()}: a
 * stage store holds the stages of a trip that already exists.
 *
 * Identity is what this pins: a stage keeps its identifier across every write, which is
 * what makes it addressable and what the per-stage enrichment writes target (ADR-066).
 */
abstract class TripStageStoreContractTestCase extends KernelTestCase
{
    protected TripStageStoreInterface $store;

    protected TripRequestRepositoryInterface $trips;

    abstract protected function createStore(): TripStageStoreInterface;

    abstract protected function createTripRepository(): TripRequestRepositoryInterface;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->store = $this->createStore();
        $this->trips = $this->createTripRepository();
    }

    #[Test]
    public function storedStagesReadBackWithTheirIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        self::assertCount(3, $stages);
        self::assertSame(
            array_map(static fn (Stage $stage): int => $stage->dayNumber, $stages),
            [1, 2, 3],
        );

        foreach ($stages as $stage) {
            self::assertTrue(Uuid::isValid($stage->id));
        }
    }

    #[Test]
    public function identifiersSurviveARewrite(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->idsOf($tripId);

        $this->store->storeStages($tripId, $this->store->getStages($tripId) ?? []);

        self::assertSame($before, $this->idsOf($tripId));
    }

    #[Test]
    public function identifiersSurviveAReorder(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];
        [$first, $second, $third] = $stages;

        $this->store->storeStages($tripId, [$third, $first, $second]);

        self::assertSame([$third->id, $first->id, $second->id], $this->idsOf($tripId));
    }

    #[Test]
    public function aRemovedStageDisappearsAndTheOthersKeepTheirIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        [$first, , $third] = $this->store->getStages($tripId) ?? [];

        $this->store->storeStages($tripId, [$first, $third]);

        self::assertSame([$first->id, $third->id], $this->idsOf($tripId));
    }

    /** A pacing regeneration builds new stages, so it replaces the identities. */
    #[Test]
    public function aFullReplacementYieldsNewIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->idsOf($tripId);

        $this->store->storeStages($tripId, [$this->stage($tripId, 1), $this->stage($tripId, 2)]);

        self::assertSame([], array_intersect($before, $this->idsOf($tripId)));
    }

    #[Test]
    public function aTargetedWriteLandsOnTheAddressedStageOnly(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        $this->store->updateStageLabels($tripId, $stages[1]->id, 'Lyon', 'Vienne');

        $after = $this->store->getStages($tripId) ?? [];
        self::assertSame('Lyon', $after[1]->startLabel);
        self::assertNull($after[0]->startLabel);
        self::assertNull($after[2]->startLabel);
    }

    /**
     * The failure mode this rules out is not a lost write but a misapplied one: day numbers
     * are renumbered by every structural edit, so a write addressed by day would land on a
     * different stage after a reorder.
     */
    #[Test]
    public function aTargetedWriteFollowsTheStageAcrossAReorder(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];
        $target = $stages[0];

        $this->store->storeStages($tripId, [$stages[2], $stages[1], $target]);
        $this->store->updateStageWeather($tripId, $target->id, $this->weather());

        $after = $this->store->getStages($tripId) ?? [];
        self::assertNull($after[0]->weather);
        self::assertNull($after[1]->weather);
        self::assertInstanceOf(WeatherForecast::class, $after[2]->weather);
    }

    #[Test]
    public function aTargetedWriteToAnUnknownStageChangesNothing(): void
    {
        $tripId = $this->seedTrip();

        $this->store->updateStageLabels($tripId, Uuid::v7()->toRfc4122(), 'Lyon', 'Vienne');

        foreach ($this->store->getStages($tripId) ?? [] as $stage) {
            self::assertNull($stage->startLabel);
        }
    }

    #[Test]
    public function mutatingAppliesAndPersistsInOneStep(): void
    {
        $tripId = $this->seedTrip();

        $written = $this->store->mutateStages($tripId, static function (array $stages): array {
            $stages[0]->label = 'edited';

            return $stages;
        });

        self::assertNotNull($written);
        self::assertSame('edited', $written->stages[0]->label);
        self::assertSame('edited', ($this->store->getStages($tripId) ?? [])[0]->label);
    }

    /**
     * The version handed back names *this* write, not whatever the trip is at when the
     * caller gets round to asking.
     *
     * Read afterwards, it could belong to a concurrent edit or a worker that bumped it in
     * the meantime. The caller would then stamp its messages with a later, unrelated
     * generation — and `isStale()` only rejects `messageGeneration < current`, so those
     * messages would never be rejected despite being built from older data. The staleness
     * guard would quietly stop guarding, which is the failure ADR-066 exists to close.
     */
    #[Test]
    public function theVersionHandedBackIsTheOneThisWriteProduced(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->store->getVersion($tripId);
        self::assertNotNull($before);

        $written = $this->store->mutateStages($tripId, static fn (array $stages): array => $stages);
        self::assertNotNull($written);
        self::assertSame($before + 1, $written->version);

        // Somebody else writes right after. The value already handed out must not move.
        $this->store->bumpVersion($tripId);

        self::assertSame($before + 1, $written->version);
        self::assertSame($before + 2, $this->store->getVersion($tripId));
    }

    #[Test]
    public function mutatingAnUnknownTripYieldsNull(): void
    {
        self::assertNull($this->store->mutateStages(
            Uuid::v7()->toRfc4122(),
            static fn (array $stages): array => $stages,
        ));
    }

    /**
     * An insertion renumbers the days and moves the end date in the write that bumped the
     * version, not in a second write the recomputation could overtake.
     */
    #[Test]
    public function aResequencedInsertionRenumbersAndMovesTheEndDateInTheSameWrite(): void
    {
        $tripId = $this->seedTripStarting('2026-07-01', '2026-07-03');
        $before = $this->store->getVersion($tripId);
        self::assertNotNull($before);

        $written = $this->store->mutateStages($tripId, function (array $stages) use ($tripId): array {
            array_splice($stages, 1, 0, [$this->stage($tripId, 99)]);

            return $stages;
        }, resequence: true);

        self::assertNotNull($written);
        self::assertSame($before + 1, $written->version);
        self::assertSame([1, 2, 3, 4], array_map(static fn (Stage $stage): int => $stage->dayNumber, $this->store->getStages($tripId) ?? []));
        self::assertSame('2026-07-04', $this->trips->getRequest($tripId)?->endDate?->format('Y-m-d'));
        self::assertSame($before + 1, $this->store->getVersion($tripId));
    }

    /**
     * A reorder keeps the stage count, so it leaves the end date where it was, even when that
     * date does not match the count (pacing does not maintain it).
     */
    #[Test]
    public function aResequencedMoveRenumbersAndLeavesTheEndDate(): void
    {
        $tripId = $this->seedTripStarting('2026-07-01', '2026-07-10');

        $this->store->mutateStages($tripId, static fn (array $stages): array => array_reverse($stages), resequence: true);

        $stages = $this->store->getStages($tripId) ?? [];
        self::assertSame([1, 2, 3], array_map(static fn (Stage $stage): int => $stage->dayNumber, $stages));
        self::assertSame([43.0, 42.0, 41.0], array_map(static fn (Stage $stage): float => $stage->distance, $stages));
        self::assertSame('2026-07-10', $this->trips->getRequest($tripId)?->endDate?->format('Y-m-d'));
    }

    #[Test]
    public function anUnsequencedMutationKeepsTheDayNumbersAndTheEndDate(): void
    {
        $tripId = $this->seedTripStarting('2026-07-01', '2026-07-03');

        $this->store->mutateStages($tripId, static fn (array $stages): array => \array_slice($stages, 0, 2));

        self::assertSame([1, 2], array_map(static fn (Stage $stage): int => $stage->dayNumber, $this->store->getStages($tripId) ?? []));
        self::assertSame('2026-07-03', $this->trips->getRequest($tripId)?->endDate?->format('Y-m-d'));
    }

    #[Test]
    public function writingTheCollectionBumpsTheVersionAndATargetedWriteDoesNot(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        $afterSeed = $this->store->getVersion($tripId);
        self::assertNotNull($afterSeed);

        $this->store->storeStages($tripId, $stages);
        $afterWrite = $this->store->getVersion($tripId);
        self::assertSame($afterSeed + 1, $afterWrite);

        $this->store->updateStageLabels($tripId, $stages[0]->id, 'Lyon', null);
        self::assertSame($afterWrite, $this->store->getVersion($tripId));
    }

    /**
     * Two producers writing different groups on the same stage must both survive.
     *
     * This is the property that lets thirteen enrichments run in parallel without a lock:
     * each replaces its own key and leaves the others alone (ADR-068). A read-modify-write of
     * the whole column would keep only the last writer.
     */
    #[Test]
    public function oneGroupWriteLeavesTheOtherGroupsAlone(): void
    {
        $tripId = $this->seedTrip();
        $stageId = ($this->store->getStages($tripId) ?? [])[0]->id;

        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::FERRY, [
            ['code' => 'ferry_crossing', 'type' => 'warning', 'message' => 'Ferry'],
        ]);
        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::CALENDAR, [
            ['code' => 'calendar_sunday', 'type' => 'nudge', 'message' => 'Sunday'],
        ]);

        $groups = array_column(($this->store->getStages($tripId) ?? [])[0]->alerts, 'group');
        sort($groups);
        self::assertSame(['calendar', 'ferry'], $groups);
    }

    /** Re-running one producer replaces its own alerts rather than appending to them. */
    #[Test]
    public function reRunningAProducerReplacesItsOwnGroup(): void
    {
        $tripId = $this->seedTrip();
        $stageId = ($this->store->getStages($tripId) ?? [])[0]->id;

        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::FORD, [
            ['code' => 'ford_crossing_wet', 'type' => 'warning', 'message' => 'Wet ford'],
        ]);
        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::FORD, [
            ['code' => 'ford_crossing_dry', 'type' => 'nudge', 'message' => 'Dry ford'],
        ]);

        $alerts = ($this->store->getStages($tripId) ?? [])[0]->alerts;
        self::assertCount(1, $alerts);
        self::assertSame('ford_crossing_dry', $alerts[0]['code']);
    }

    /**
     * A trip-wide replacement clears the group on the stages the new set does not mention.
     *
     * The calendar check recomputes every stage at once, so a stage that dropped out has to
     * lose its nudge — the "Sunday bug" the client mirrors in `reconciliation.ts`. A loop over
     * the new set alone cannot express "and clear everyone else".
     */
    #[Test]
    public function aTripWideReplacementClearsTheStagesItDoesNotMention(): void
    {
        $tripId = $this->seedTrip();
        $ids = $this->idsOf($tripId);

        $this->store->updateTripAlertsForGroup($tripId, AlertGroup::CALENDAR, [
            $ids[0] => [['code' => 'calendar_sunday', 'type' => 'nudge', 'message' => 'Sunday']],
            $ids[1] => [['code' => 'calendar_sunday', 'type' => 'nudge', 'message' => 'Sunday']],
        ]);
        $this->store->updateTripAlertsForGroup($tripId, AlertGroup::CALENDAR, [
            $ids[1] => [['code' => 'calendar_public_holiday', 'type' => 'nudge', 'message' => 'Holiday']],
        ]);

        $after = $this->store->getStages($tripId) ?? [];
        self::assertSame([], $after[0]->alerts, 'A stage outside the new set keeps no stale nudge.');
        self::assertCount(1, $after[1]->alerts);
        self::assertSame('calendar_public_holiday', $after[1]->alerts[0]['code']);
    }

    /**
     * Alerts come back exactly as the producer wrote them.
     *
     * These fields are the ones {@see \App\ApiResource\Model\Alert} does not model, and that
     * normalising used to drop on the way through.
     */
    #[Test]
    public function producerSpecificFieldsSurviveTheRoundTrip(): void
    {
        $tripId = $this->seedTrip();
        $stageId = ($this->store->getStages($tripId) ?? [])[0]->id;

        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::CULTURAL_POI, [[
            'code' => 'cultural_poi_suggestion',
            'type' => 'nudge',
            'message' => 'Abbey',
            'poiName' => 'Abbaye de Fontenay',
            'openingHours' => 'Mo-Su 10:00-18:00',
            'estimatedPrice' => 12.5,
            'wikidataId' => 'Q1145',
        ]]);

        $alert = ($this->store->getStages($tripId) ?? [])[0]->alerts[0];
        self::assertSame('Abbaye de Fontenay', $alert['poiName']);
        self::assertSame('Mo-Su 10:00-18:00', $alert['openingHours']);
        self::assertSame(12.5, $alert['estimatedPrice']);
        self::assertSame('Q1145', $alert['wikidataId']);
        self::assertSame('cultural_poi', $alert['group']);
    }

    /** An empty result clears the group: found nothing is a result, not an absence of one. */
    #[Test]
    public function anEmptyResultClearsTheGroup(): void
    {
        $tripId = $this->seedTrip();
        $stageId = ($this->store->getStages($tripId) ?? [])[0]->id;

        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::BIKE_SHOP, [
            ['code' => 'bike_shop_none_nearby', 'type' => 'nudge', 'message' => 'No shop'],
        ]);
        $this->store->updateStageAlertsForGroup($tripId, $stageId, AlertGroup::BIKE_SHOP, []);

        $stage = ($this->store->getStages($tripId) ?? [])[0];
        self::assertSame([], $stage->alerts);
        // The key stays: "ran and found nothing" is not "never ran", and nothing else in the
        // model carries that distinction. Asserting only on the flat view would let an
        // implementation drop the key and still look right.
        self::assertArrayHasKey(AlertGroup::BIKE_SHOP->value, $stage->alertsByGroup);
        self::assertArrayNotHasKey(AlertGroup::FERRY->value, $stage->alertsByGroup);
    }

    /**
     * A structural edit must not carry an enrichment snapshot back over a producer's write.
     *
     * `storeStages()` writes the structure; the enrichment columns belong to the targeted
     * writers. This is the partition lot A deferred and ADR-068 introduces.
     */
    #[Test]
    public function storingTheCollectionDoesNotTouchTheAlerts(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        $this->store->updateStageAlertsForGroup($tripId, $stages[0]->id, AlertGroup::FERRY, [
            ['code' => 'ferry_crossing', 'type' => 'warning', 'message' => 'Ferry'],
        ]);

        // A stale snapshot on purpose: these DTOs were read before the alert was written.
        $this->store->storeStages($tripId, $stages);

        self::assertCount(1, ($this->store->getStages($tripId) ?? [])[0]->alerts);
    }

    #[Test]
    public function theStageGeometryIsReadByIdentifier(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        // Loose comparison: JSONB round-trips a whole number back as an int, where the
        // in-memory implementation keeps the float it was given.
        self::assertEquals(
            [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.2, 'lon' => 2.2]],
            $this->store->getStageGeometry($tripId, $stages[0]->id),
        );
        self::assertNull($this->store->getStageGeometry($tripId, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function aDayNumberResolvesToItsStageIdentifier(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->store->getStages($tripId) ?? [];

        self::assertSame($stages[1]->id, $this->store->getStageIdByDayNumber($tripId, 2));
        self::assertNull($this->store->getStageIdByDayNumber($tripId, 99));
    }

    /** @return list<string> */
    private function idsOf(string $tripId): array
    {
        return array_map(
            static fn (Stage $stage): string => $stage->id,
            $this->store->getStages($tripId) ?? [],
        );
    }

    protected function seedTrip(): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->trips->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->store->storeStages($tripId, [
            $this->stage($tripId, 1),
            $this->stage($tripId, 2),
            $this->stage($tripId, 3),
        ]);

        return $tripId;
    }

    private function seedTripStarting(string $startDate, string $endDate): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->startDate = new \DateTimeImmutable($startDate);
        $request->endDate = new \DateTimeImmutable($endDate);

        $this->trips->initializeTrip($tripId, $request);
        $this->store->storeStages($tripId, [
            $this->stage($tripId, 1),
            $this->stage($tripId, 2),
            $this->stage($tripId, 3),
        ]);

        return $tripId;
    }

    private function stage(string $tripId, int $dayNumber): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 40.0 + $dayNumber,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
            geometry: [new Coordinate(48.0, 2.0), new Coordinate(48.2, 2.2)],
        );
    }

    private function weather(): WeatherForecast
    {
        return new WeatherForecast(
            icon: '10d',
            description: 'Rain',
            tempMin: 12.0,
            tempMax: 18.0,
            windSpeed: 10.0,
            windDirection: 'N',
            precipitationProbability: 80,
            humidity: 70,
            comfortIndex: 90,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );
    }
}
