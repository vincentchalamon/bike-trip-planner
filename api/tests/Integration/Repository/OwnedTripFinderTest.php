<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use Symfony\Component\Uid\Uuid;
use App\ApiResource\TripRequest;
use App\Entity\Stage;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The trips read through their owner: ownership, the list page and its filters, the export,
 * and the stage aggregates both read on the side.
 *
 * Integration coverage for the weather-safety batch lookup (#1124): the coverage
 * logic of findOwnedTripsCoveringDate (started on/before the day and not yet ended,
 * including a long-haul trip started months ago and an open-ended trip with no
 * endDate) and the exclusion of ended, future, undated and anonymous trips. Only
 * exercised through stubs elsewhere, so a dropped filter would go undetected.
 */
#[ResetDatabase]
final class OwnedTripFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private DoctrineTripRequestRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(DoctrineTripRequestRepository::class);
    }

    #[Test]
    public function returnsOnlyOwnedDatedTripsWhoseRangeCoversTheDay(): void
    {
        $date = new \DateTimeImmutable('2026-06-15');
        $owner = $this->persistUser('owner@example.com');

        // Covering the day (startDate <= date <= endDate).
        $startsOnDate = $this->persistTrip($owner, $date, $date->modify('+5 days'));
        $midTrip = $this->persistTrip($owner, $date->modify('-3 days'), $date->modify('+2 days'));
        $endsOnDate = $this->persistTrip($owner, $date->modify('-4 days'), $date);
        // The regression case: a long-haul trip that started 90 days ago and still
        // runs — a fixed 60-day look-back would have silently dropped it.
        $longHaul = $this->persistTrip($owner, $date->modify('-90 days'), $date->modify('+10 days'));
        // Open-ended (startDate set, endDate null) counts as not-yet-ended.
        $openEnded = $this->persistTrip($owner, $date->modify('-2 days'), null);

        // Not covering the day, one reason each.
        $this->persistTrip($owner, $date->modify('-10 days'), $date->modify('-1 day')); // already ended
        $this->persistTrip($owner, $date->modify('+1 day'), $date->modify('+5 days'));  // starts tomorrow
        $this->persistTrip($owner, $date->modify('+1 day'), null);                       // open-ended, future start
        $this->persistTrip($owner, null, null);                                          // undated
        $this->persistTrip(null, $date, $date->modify('+3 days'));                       // anonymous

        $ids = array_map(
            static fn (TripRequest $t): string => $t->id?->toRfc4122() ?? '',
            $this->repository->findOwnedTripsCoveringDate($date),
        );
        sort($ids);

        $expected = [$startsOnDate->id, $midTrip->id, $endsOnDate->id, $longHaul->id, $openEnded->id];
        $expected = array_map(static fn (?Uuid $id): string => $id?->toRfc4122() ?? '', $expected);
        sort($expected);

        self::assertSame($expected, $ids);
    }

    #[Test]
    public function ownershipIsTheOwnersAloneAndAMalformedIdentifierOwnsNothing(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $stranger = $this->persistUser('stranger@example.com');
        $trip = $this->persistTrip($owner, null, null);
        $tripId = $trip->id?->toRfc4122() ?? '';

        self::assertTrue($this->repository->isOwnedBy($tripId, $owner));
        self::assertFalse($this->repository->isOwnedBy($tripId, $stranger));
        self::assertFalse($this->repository->isOwnedBy(Uuid::v7()->toRfc4122(), $owner));
        self::assertFalse($this->repository->isOwnedBy('not-a-uuid', $owner));
    }

    #[Test]
    public function aPageIsTheOwnersTripsNewestFirstWithTheIdentifierBreakingTies(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $this->persistTrip($this->persistUser('other@example.com'), null, null);
        $sameSecond = new \DateTimeImmutable('2026-05-01 10:00:00');
        $older = $this->persistTrip($owner, null, null, createdAt: $sameSecond->modify('-1 day'));
        $first = $this->persistTrip($owner, null, null, createdAt: $sameSecond);
        $second = $this->persistTrip($owner, null, null, createdAt: $sameSecond);

        self::assertSame(3, $this->repository->countOwnedBy($owner, null, null, null));
        self::assertSame(
            $this->ids([$second, $first]),
            $this->ids($this->repository->findPageOwnedBy($owner, null, null, null, 0, 2)),
        );
        self::assertSame(
            $this->ids([$older]),
            $this->ids($this->repository->findPageOwnedBy($owner, null, null, null, 2, 2)),
        );
    }

    #[Test]
    public function theListFiltersOnAPartialTitleAndOnTheTripsOwnDates(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $june = $this->persistTrip($owner, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-10'), title: 'Tour du Vercors');
        $july = $this->persistTrip($owner, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-10'), title: 'Loire 100%');
        $this->persistTrip($owner, null, null, title: 'Loire_sans_dates');

        self::assertSame($this->ids([$june]), $this->ids($this->repository->findPageOwnedBy($owner, 'VERCORS', null, null, 0, 10)));
        // % and _ are matched literally, not as wildcards.
        self::assertSame($this->ids([$july]), $this->ids($this->repository->findPageOwnedBy($owner, '100%', null, null, 0, 10)));
        self::assertSame(0, $this->repository->countOwnedBy($owner, 'Loire_s_', null, null));

        $startsFrom = new \DateTimeImmutable('2026-06-15');
        $endsBy = new \DateTimeImmutable('2026-06-10');
        self::assertSame($this->ids([$july]), $this->ids($this->repository->findPageOwnedBy($owner, null, $startsFrom, null, 0, 10)));
        self::assertSame(1, $this->repository->countOwnedBy($owner, null, $startsFrom, null));
        self::assertSame($this->ids([$june]), $this->ids($this->repository->findPageOwnedBy($owner, null, null, $endsBy, 0, 10)));
        self::assertSame(1, $this->repository->countOwnedBy($owner, null, null, $endsBy));
    }

    #[Test]
    public function theExportReadsEveryOwnedTripOldestFirst(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $this->persistTrip($this->persistUser('other@example.com'), null, null);
        $newer = $this->persistTrip($owner, null, null, createdAt: new \DateTimeImmutable('2026-05-02'));
        $older = $this->persistTrip($owner, null, null, createdAt: new \DateTimeImmutable('2026-05-01'));

        self::assertSame($this->ids([$older, $newer]), $this->ids($this->repository->findAllOwnedBy($owner)));
    }

    #[Test]
    public function stageTotalsLeaveRestDaysOutAndTripsWithoutRiddenStagesAbsent(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $ridden = $this->persistTrip($owner, null, null);
        $restOnly = $this->persistTrip($owner, null, null);
        $empty = $this->persistTrip($owner, null, null);
        $this->persistStage($ridden, 0, 42.5);
        $this->persistStage($ridden, 1, 0.0, restDay: true);
        $this->persistStage($ridden, 2, 30.0);
        $this->persistStage($restOnly, 0, 0.0, restDay: true);

        self::assertEquals(
            [$this->ids([$ridden])[0] => [72.5, 2]],
            $this->repository->stageTotalsByTrip($this->uuids([$ridden, $restOnly, $empty])),
        );
        self::assertSame([], $this->repository->stageTotalsByTrip([]));
    }

    #[Test]
    public function stageSummariesComeInStageOrderPerTrip(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $trip = $this->persistTrip($owner, null, null);
        $other = $this->persistTrip($owner, null, null);
        $this->persistStage($trip, 1, 30.0, label: 'B');
        $this->persistStage($trip, 0, 42.5, label: 'A');
        $this->persistStage($other, 0, 10.0, label: 'C');

        $summaries = $this->repository->stageSummariesByTrip($this->uuids([$trip]));
        $tripId = $this->ids([$trip])[0];

        self::assertSame([$tripId], array_keys($summaries));
        self::assertSame(['A', 'B'], array_column($summaries[$tripId], 'label'));
        self::assertEquals(
            ['dayNumber' => 1, 'label' => 'A', 'distance' => 42.5, 'elevation' => 100.0],
            $summaries[$tripId][0],
        );
    }

    /**
     * @param list<TripRequest> $trips
     *
     * @return list<string>
     */
    private function ids(array $trips): array
    {
        return array_map(static fn (TripRequest $trip): string => $trip->id?->toRfc4122() ?? '', $trips);
    }

    /**
     * @param list<TripRequest> $trips
     *
     * @return list<Uuid>
     */
    private function uuids(array $trips): array
    {
        return array_map(static function (TripRequest $trip): Uuid {
            \assert($trip->id instanceof Uuid);

            return $trip->id;
        }, $trips);
    }

    private function persistStage(TripRequest $trip, int $position, float $distance, bool $restDay = false, ?string $label = null): void
    {
        $stage = new Stage($trip);
        $stage->setPosition($position);
        $stage->setDayNumber($position + 1);
        $stage->setDistance($distance);
        $stage->setElevation(100.0);
        $stage->setStartLat(48.0);
        $stage->setStartLon(2.0);
        $stage->setEndLat(48.1);
        $stage->setEndLon(2.1);
        $stage->setIsRestDay($restDay);
        $stage->setLabel($label);

        $this->em->persist($stage);
        $this->em->flush();
    }

    private function persistTrip(?User $user, ?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate, ?\DateTimeImmutable $createdAt = null, ?string $title = null): TripRequest
    {
        $trip = new TripRequest();
        $trip->user = $user;
        $trip->startDate = $startDate;
        $trip->endDate = $endDate;
        $trip->title = $title;
        $trip->sourceUrl = 'https://www.komoot.com/tour/123456789';
        if ($createdAt instanceof \DateTimeImmutable) {
            $trip->createdAt = $createdAt;
        }

        $this->em->persist($trip);
        $this->em->flush();

        return $trip;
    }

    /**
     * @param non-empty-string $email
     */
    private function persistUser(string $email): User
    {
        $user = new User($email);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
