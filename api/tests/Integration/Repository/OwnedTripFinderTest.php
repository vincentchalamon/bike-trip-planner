<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use Symfony\Component\Uid\Uuid;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the weather-safety batch lookup (#1124): the coverage
 * logic of findOwnedTripsCoveringDate (startDate <= date <= endDate, including a
 * long-haul trip that started well over two months ago) and the exclusion of
 * ended, future, undated and anonymous trips. Only exercised through stubs
 * elsewhere, so a dropped filter would go undetected.
 */
final class OwnedTripFinderTest extends KernelTestCase
{
    use ResetDatabase;

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

        // Not covering the day, one reason each.
        $this->persistTrip($owner, $date->modify('-10 days'), $date->modify('-1 day')); // already ended
        $this->persistTrip($owner, $date->modify('+1 day'), $date->modify('+5 days'));  // starts tomorrow
        $this->persistTrip($owner, null, null);                                          // undated
        $this->persistTrip(null, $date, $date->modify('+3 days'));                       // anonymous

        $ids = array_map(
            static fn (TripRequest $t): string => $t->id?->toRfc4122() ?? '',
            $this->repository->findOwnedTripsCoveringDate($date),
        );
        sort($ids);

        $expected = [$startsOnDate->id, $midTrip->id, $endsOnDate->id, $longHaul->id];
        $expected = array_map(static fn (?Uuid $id): string => $id?->toRfc4122() ?? '', $expected);
        sort($expected);

        self::assertSame($expected, $ids);
    }

    private function persistTrip(?User $user, ?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): TripRequest
    {
        $trip = new TripRequest();
        $trip->user = $user;
        $trip->startDate = $startDate;
        $trip->endDate = $endDate;
        $trip->sourceUrl = 'https://www.komoot.com/tour/123456789';

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
