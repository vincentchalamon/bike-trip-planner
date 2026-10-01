<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A trip's own fields, written and read back through the database: every store*() method
 * leaves its write flushed, which the entity manager is cleared to prove.
 */
#[CoversClass(DoctrineTripRequestRepository::class)]
#[ResetDatabase]
final class DoctrineTripRequestRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private DoctrineTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var DoctrineTripRequestRepository $repository */
        $repository = $container->get(DoctrineTripRequestRepository::class);
        $this->repository = $repository;
    }

    /**
     * A creation carries settings and an owner, and decides nothing else.
     *
     * It used to persist the caller's object outright, which is how a request body reached
     * `status`, `version`, `createdAt`, `outOfZone`, `sourceType` and `computationStatus` —
     * `#[ApiProperty(writable: false)]` never stood in the way, because the serializer only
     * consults it for a class that is an `#[ApiResource]`.
     */
    #[Test]
    public function aCreationKeepsTheSettingsAndTheOwnerAndNothingServerOwned(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $owner = new User('owner@test.com');
        $this->em->persist($owner);
        $this->em->flush();

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');
        $request->endDate = new \DateTimeImmutable('2026-07-10');
        $request->fatigueFactor = 0.85;
        $request->elevationPenalty = 40.0;
        $request->ebikeMode = true;
        $request->departureHour = 9;
        $request->maxDistancePerDay = 60.0;
        $request->averageSpeed = 18.0;
        $request->enabledAccommodationTypes = ['camp_site', 'hotel'];
        $request->user = $owner;

        // Everything a deserialized body could have set, and none of which is the caller's to
        // decide.
        $request->status = 'ready';
        $request->version = 4242;
        $request->outOfZone = true;
        $request->sourceType = 'forged';
        $request->computationStatus = ['route' => 'done'];
        $request->createdAt = new \DateTimeImmutable('2001-01-01');

        $this->repository->initializeTrip($tripId, $request, 'fr');

        $persisted = $this->reread($tripId);

        self::assertSame($owner->getId()->toRfc4122(), $persisted->user?->getId()->toRfc4122());
        self::assertSame('https://www.komoot.com/tour/123456789', $persisted->sourceUrl);
        self::assertSame('2026-07-01', $persisted->startDate?->format('Y-m-d'));
        self::assertSame('2026-07-10', $persisted->endDate?->format('Y-m-d'));
        self::assertSame(0.85, $persisted->fatigueFactor);
        self::assertSame(40.0, $persisted->elevationPenalty);
        self::assertTrue($persisted->ebikeMode);
        self::assertSame(9, $persisted->departureHour);
        self::assertSame(60.0, $persisted->maxDistancePerDay);
        self::assertSame(18.0, $persisted->averageSpeed);
        self::assertSame(['camp_site', 'hotel'], $persisted->enabledAccommodationTypes);
        // The locale rides in the same flush rather than a second storeLocale().
        self::assertSame('fr', $persisted->locale);

        self::assertSame('draft', $persisted->status);
        self::assertSame(1, $persisted->version);
        self::assertFalse($persisted->outOfZone);
        self::assertNull($persisted->sourceType);
        self::assertSame([], $persisted->computationStatus);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $persisted->createdAt);
    }

    #[Test]
    public function aSecondInitializationAppliesTheSettingsToTheExistingTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $first = new TripRequest();
        $first->sourceUrl = 'https://www.komoot.com/tour/111';
        $first->fatigueFactor = 0.9;

        $this->repository->initializeTrip($tripId, $first);

        $updated = new TripRequest();
        $updated->sourceUrl = 'https://www.komoot.com/tour/222';
        $updated->fatigueFactor = 0.8;

        $this->repository->initializeTrip($tripId, $updated);

        $persisted = $this->reread($tripId);
        self::assertSame('https://www.komoot.com/tour/222', $persisted->sourceUrl);
        self::assertSame(0.8, $persisted->fatigueFactor);
        self::assertSame(1, $this->repository->count([]));
    }

    #[Test]
    public function aDetachedRequestIsCopiedOntoTheRow(): void
    {
        $tripId = $this->trip();

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/222';
        $request->fatigueFactor = 0.8;
        $request->title = 'Copied';

        $this->repository->storeRequest($tripId, $request);

        $persisted = $this->reread($tripId);
        self::assertSame('https://www.komoot.com/tour/222', $persisted->sourceUrl);
        self::assertSame(0.8, $persisted->fatigueFactor);
        self::assertSame('Copied', $persisted->title);
    }

    #[Test]
    public function eachFieldWriteIsFlushed(): void
    {
        $tripId = $this->trip();

        $this->repository->storeTitle($tripId, 'Mon voyage');
        $this->repository->storeSourceType($tripId, 'komoot');
        $this->repository->storeLocale($tripId, 'fr');
        $this->repository->storeStatus($tripId, 'ready');

        $this->em->clear();
        self::assertSame('Mon voyage', $this->repository->getTitle($tripId));
        self::assertSame('komoot', $this->repository->getSourceType($tripId));
        self::assertSame('fr', $this->repository->getLocale($tripId));
        self::assertSame('ready', $this->reread($tripId)->status);
    }

    #[Test]
    public function anUnknownOrMalformedTripIsReadAsNullAndWrittenAsNothing(): void
    {
        $unknown = Uuid::v7()->toRfc4122();

        self::assertNull($this->repository->getRequest('not-a-valid-uuid'));
        self::assertNull($this->repository->getRequest($unknown));

        $this->repository->storeStatus($unknown, 'ready');
        $this->repository->storeTitle('not-a-valid-uuid', 'Nope');

        self::assertSame(0, $this->repository->count([]));
    }

    private function trip(): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest());

        return $tripId;
    }

    private function reread(string $tripId): TripRequest
    {
        $this->em->clear();
        $trip = $this->repository->getRequest($tripId);
        self::assertInstanceOf(TripRequest::class, $trip);

        return $trip;
    }
}
