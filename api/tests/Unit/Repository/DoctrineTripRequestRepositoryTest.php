<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use PHPUnit\Framework\MockObject\MockObject;
use Doctrine\ORM\Mapping\ClassMetadata;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DoctrineTripRequestRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class DoctrineTripRequestRepositoryTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private DoctrineTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $classMetadata = new ClassMetadata(TripRequest::class);
        $this->entityManager->method('getClassMetadata')->willReturn($classMetadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->repository = new DoctrineTripRequestRepository($registry);
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
    public function initializeTripAndGetRequestRoundtrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $owner = new User('owner@test.com');

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

        $persisted = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $trip) use (&$persisted): void {
                $persisted = $trip;
            });
        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->initializeTrip($tripId, $request);

        self::assertInstanceOf(TripRequest::class, $persisted);
        self::assertNotSame($request, $persisted, 'The caller object is persisted as-is, so every public property of it reaches the row.');
        self::assertSame($tripId, $persisted->id?->toRfc4122());
        self::assertSame($owner, $persisted->user);

        self::assertSame('draft', $persisted->status);
        self::assertSame(1, $persisted->version);
        self::assertFalse($persisted->outOfZone);
        self::assertNull($persisted->sourceType);
        self::assertSame([], $persisted->computationStatus);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $persisted->createdAt);

        // Now test getRequest by having find() return the row that was persisted
        $request = $persisted;
        $em2 = $this->createMock(EntityManagerInterface::class);
        $em2->method('find')
            ->willReturn($request);
        $em2->method('getClassMetadata')
            ->willReturn(new ClassMetadata(TripRequest::class));

        $registry2 = $this->createMock(ManagerRegistry::class);
        $registry2->method('getManagerForClass')->willReturn($em2);

        $repo2 = new DoctrineTripRequestRepository($registry2);
        $result = $repo2->getRequest($tripId);

        self::assertSame($request, $result);
        self::assertSame('https://www.komoot.com/tour/123456789', $result->sourceUrl);
        self::assertSame('2026-07-01', $result->startDate?->format('Y-m-d'));
        self::assertSame('2026-07-10', $result->endDate?->format('Y-m-d'));
        self::assertSame(0.85, $result->fatigueFactor);
        self::assertSame(40.0, $result->elevationPenalty);
        self::assertTrue($result->ebikeMode);
        self::assertSame(9, $result->departureHour);
        self::assertSame(60.0, $result->maxDistancePerDay);
        self::assertSame(18.0, $result->averageSpeed);
        self::assertSame(['camp_site', 'hotel'], $result->enabledAccommodationTypes);
    }

    #[Test]
    public function getRequestReturnsNullForInvalidUuid(): void
    {
        $this->entityManager->expects(self::never())
            ->method('find');

        $result = $this->repository->getRequest('not-a-valid-uuid');

        self::assertNull($result);
    }

    #[Test]
    public function getRequestReturnsNullForNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->entityManager->method('find')
            ->willReturn(null);

        $result = $this->repository->getRequest($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function storeTitleUpdatesEntity(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));

        $this->entityManager->method('find')
            ->willReturn($trip);

        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->storeTitle($tripId, 'Mon voyage');

        self::assertSame('Mon voyage', $trip->title);
    }

    #[Test]
    public function storeRequestUpdatesExistingTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $managed = new TripRequest(Uuid::fromString($tripId));
        $managed->sourceUrl = 'https://www.komoot.com/tour/111';

        $this->entityManager->method('find')
            ->willReturn($managed);
        $this->entityManager->expects(self::once())
            ->method('flush');

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/222';
        $request->fatigueFactor = 0.8;

        $this->repository->storeRequest($tripId, $request);

        self::assertSame('https://www.komoot.com/tour/222', $managed->sourceUrl);
        self::assertSame(0.8, $managed->fatigueFactor);
    }

    #[Test]
    public function storeSourceType(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));

        $this->entityManager->method('find')
            ->willReturn($trip);

        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->storeSourceType($tripId, 'komoot');
        self::assertSame('komoot', $trip->sourceType);
    }

    /**
     * The creation paths used to follow initializeTrip() with storeLocale(): two flushes for
     * one row.
     */
    #[Test]
    public function initializeTripWritesTheLocaleInTheSameFlush(): void
    {
        $persisted = null;
        $this->entityManager->method('persist')->willReturnCallback(static function (object $trip) use (&$persisted): void {
            $persisted = $trip;
        });
        $this->entityManager->expects(self::once())->method('flush');

        $this->repository->initializeTrip(Uuid::v7()->toRfc4122(), new TripRequest(), 'fr');

        self::assertInstanceOf(TripRequest::class, $persisted);
        self::assertSame('fr', $persisted->locale);
    }

    #[Test]
    public function storeLocale(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));

        $this->entityManager->method('find')
            ->willReturn($trip);

        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->storeLocale($tripId, 'fr');
        self::assertSame('fr', $trip->locale);
    }

    #[Test]
    public function storeStatus(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));

        self::assertSame('draft', $trip->status);

        $this->entityManager->method('find')
            ->willReturn($trip);

        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->storeStatus($tripId, 'ready');
        self::assertSame('ready', $trip->status);
    }

    #[Test]
    public function storeStatusIgnoresNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->entityManager->method('find')
            ->willReturn(null);
        $this->entityManager->expects(self::never())
            ->method('flush');

        $this->repository->storeStatus($tripId, 'ready');
    }

    #[Test]
    public function initializeTripIsIdempotent(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $existing = new TripRequest(Uuid::fromString($tripId));
        $existing->sourceUrl = 'https://www.komoot.com/tour/111';
        $existing->fatigueFactor = 0.9;

        $this->entityManager->method('find')
            ->willReturn($existing);
        $this->entityManager->expects(self::never())
            ->method('persist');
        $this->entityManager->expects(self::once())
            ->method('flush');

        $updated = new TripRequest();
        $updated->sourceUrl = 'https://www.komoot.com/tour/222';
        $updated->fatigueFactor = 0.8;

        $this->repository->initializeTrip($tripId, $updated);

        self::assertSame('https://www.komoot.com/tour/222', $existing->sourceUrl);
        self::assertSame(0.8, $existing->fatigueFactor);
    }
}
