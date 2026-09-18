<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The behaviour every {@see TripRequestRepositoryInterface} implementation owes its
 * callers, run against each of them.
 *
 * There are two implementations and they are not interchangeable by accident: the Doctrine
 * one backs dev and prod, the Redis one is aliased in for the `test` environment
 * (`config/services.php`), so the whole functional suite exercises Redis and never the SQL
 * path. Without a shared contract the two drift silently, and a green functional suite says
 * nothing about production — the gap the #56 TODO has been naming for a while.
 *
 * Identity is what this pins: a stage keeps its identifier across every write, which is
 * what makes it addressable and what the per-stage enrichment writes target (ADR-066).
 */
abstract class TripRequestRepositoryContractTestCase extends KernelTestCase
{
    protected TripRequestRepositoryInterface $repository;

    abstract protected function createRepository(): TripRequestRepositoryInterface;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->repository = $this->createRepository();
    }

    #[Test]
    public function storedStagesReadBackWithTheirIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

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

        $this->repository->storeStages($tripId, $this->repository->getStages($tripId) ?? []);

        self::assertSame($before, $this->idsOf($tripId));
    }

    #[Test]
    public function identifiersSurviveAReorder(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        [$first, $second, $third] = $stages;

        $this->repository->storeStages($tripId, [$third, $first, $second]);

        self::assertSame([$third->id, $first->id, $second->id], $this->idsOf($tripId));
    }

    #[Test]
    public function aRemovedStageDisappearsAndTheOthersKeepTheirIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        [$first, , $third] = $this->repository->getStages($tripId) ?? [];

        $this->repository->storeStages($tripId, [$first, $third]);

        self::assertSame([$first->id, $third->id], $this->idsOf($tripId));
    }

    /** A pacing regeneration builds new stages, so it replaces the identities. */
    #[Test]
    public function aFullReplacementYieldsNewIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->idsOf($tripId);

        $this->repository->storeStages($tripId, [$this->stage($tripId, 1), $this->stage($tripId, 2)]);

        self::assertSame([], array_intersect($before, $this->idsOf($tripId)));
    }

    #[Test]
    public function aTargetedWriteLandsOnTheAddressedStageOnly(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

        $this->repository->updateStageLabels($tripId, $stages[1]->id, 'Lyon', 'Vienne');

        $after = $this->repository->getStages($tripId) ?? [];
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
        $stages = $this->repository->getStages($tripId) ?? [];
        $target = $stages[0];

        $this->repository->storeStages($tripId, [$stages[2], $stages[1], $target]);
        $this->repository->updateStageWeather($tripId, $target->id, $this->weather());

        $after = $this->repository->getStages($tripId) ?? [];
        self::assertNull($after[0]->weather);
        self::assertNull($after[1]->weather);
        self::assertInstanceOf(WeatherForecast::class, $after[2]->weather);
    }

    #[Test]
    public function aTargetedWriteToAnUnknownStageChangesNothing(): void
    {
        $tripId = $this->seedTrip();

        $this->repository->updateStageLabels($tripId, Uuid::v7()->toRfc4122(), 'Lyon', 'Vienne');

        foreach ($this->repository->getStages($tripId) ?? [] as $stage) {
            self::assertNull($stage->startLabel);
        }
    }

    #[Test]
    public function mutatingAppliesAndPersistsInOneStep(): void
    {
        $tripId = $this->seedTrip();

        $written = $this->repository->mutateStages($tripId, static function (array $stages): array {
            $stages[0]->label = 'edited';

            return $stages;
        });

        self::assertNotNull($written);
        self::assertSame('edited', $written->stages[0]->label);
        self::assertSame('edited', ($this->repository->getStages($tripId) ?? [])[0]->label);
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
        $before = $this->repository->getVersion($tripId);
        self::assertNotNull($before);

        $written = $this->repository->mutateStages($tripId, static fn (array $stages): array => $stages);
        self::assertNotNull($written);
        self::assertSame($before + 1, $written->version);

        // Somebody else writes right after. The value already handed out must not move.
        $this->repository->bumpVersion($tripId);

        self::assertSame($before + 1, $written->version);
        self::assertSame($before + 2, $this->repository->getVersion($tripId));
    }

    #[Test]
    public function mutatingAnUnknownTripYieldsNull(): void
    {
        self::assertNull($this->repository->mutateStages(
            Uuid::v7()->toRfc4122(),
            static fn (array $stages): array => $stages,
        ));
    }

    #[Test]
    public function writingTheCollectionBumpsTheVersionAndATargetedWriteDoesNot(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

        $afterSeed = $this->repository->getVersion($tripId);
        self::assertNotNull($afterSeed);

        $this->repository->storeStages($tripId, $stages);
        $afterWrite = $this->repository->getVersion($tripId);
        self::assertSame($afterSeed + 1, $afterWrite);

        $this->repository->updateStageLabels($tripId, $stages[0]->id, 'Lyon', null);
        self::assertSame($afterWrite, $this->repository->getVersion($tripId));
    }

    #[Test]
    public function theStageGeometryIsReadByIdentifier(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

        // Loose comparison: JSONB round-trips a whole number back as an int, where the
        // in-memory implementation keeps the float it was given.
        self::assertEquals(
            [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.2, 'lon' => 2.2]],
            $this->repository->getStageGeometry($tripId, $stages[0]->id),
        );
        self::assertNull($this->repository->getStageGeometry($tripId, Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function aDayNumberResolvesToItsStageIdentifier(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

        self::assertSame($stages[1]->id, $this->repository->getStageIdByDayNumber($tripId, 2));
        self::assertNull($this->repository->getStageIdByDayNumber($tripId, 99));
    }

    /** @return list<string> */
    private function idsOf(string $tripId): array
    {
        return array_map(
            static fn (Stage $stage): string => $stage->id,
            $this->repository->getStages($tripId) ?? [],
        );
    }

    protected function seedTrip(): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [
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
