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
 * Integration coverage for the weather-safety batch lookup (#1124): the boundary
 * logic of findOwnedTripsCoveringDate (startDate <= date, strict 60-day floor) and
 * the exclusion of anonymous and undated trips. Only exercised through stubs
 * elsewhere, so an off-by-one on the floor or a dropped filter would go undetected.
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
    public function returnsOnlyOwnedDatedTripsWhoseStartFallsInTheClosedOpenWindow(): void
    {
        $date = new \DateTimeImmutable('2026-06-15');
        $owner = $this->persistUser('owner@example.com');

        // Inside the (floor, date] window.
        $onDate = $this->persistTrip($owner, $date);                          // upper bound, inclusive
        $midWindow = $this->persistTrip($owner, $date->modify('-30 days'));
        $justAfterFloor = $this->persistTrip($owner, $date->modify('-59 days'));

        // Outside it, one reason each.
        $this->persistTrip($owner, $date->modify('-60 days'));   // exactly the floor: excluded (strict >)
        $this->persistTrip($owner, $date->modify('+1 day'));     // future start: excluded (<= date)
        $this->persistTrip($owner, null);                        // undated: excluded
        $this->persistTrip(null, $date);                         // anonymous: excluded

        $ids = array_map(
            static fn (TripRequest $t): string => $t->id?->toRfc4122() ?? '',
            $this->repository->findOwnedTripsCoveringDate($date),
        );
        sort($ids);

        $expected = [$onDate->id, $midWindow->id, $justAfterFloor->id];
        $expected = array_map(static fn (?Uuid $id): string => $id?->toRfc4122() ?? '', $expected);
        sort($expected);

        self::assertSame($expected, $ids);
    }

    private function persistTrip(?User $user, ?\DateTimeImmutable $startDate): TripRequest
    {
        $trip = new TripRequest();
        $trip->user = $user;
        $trip->startDate = $startDate;
        $trip->endDate = $startDate instanceof \DateTimeImmutable ? $startDate->modify('+3 days') : null;
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
