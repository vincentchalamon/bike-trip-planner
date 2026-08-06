<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\Stage as StageEntity;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for {@see \App\Repository\DoctrineTripRequestRepository::getStageGeometry}:
 * the in-ride detour input (issue #932). It must read the single `geometry` JSONB column
 * without hydrating the stage aggregate (weather, POIs, accommodations…).
 */
final class DoctrineTripRequestGeometryTest extends KernelTestCase
{
    use ResetDatabase;

    private TripRequestRepositoryInterface $repository;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repository */
        $repository = $container->get(TripRequestRepositoryInterface::class);
        $this->repository = $repository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    #[Test]
    public function returnsStagePointsInTravelOrderWithoutElevation(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->seedTrip($tripId);

        $this->entityManager->clear();

        self::assertSame(
            [
                ['lat' => 48.0, 'lon' => 2.0],
                ['lat' => 48.1, 'lon' => 2.1],
                ['lat' => 48.2, 'lon' => 2.2],
            ],
            $this->repository->getStageGeometry($tripId, 2),
        );
    }

    #[Test]
    public function doesNotHydrateTheStageAggregate(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->seedTrip($tripId);

        // Start from a cold identity map: the scalar geometry read must not pull any
        // Stage entity into the unit of work (unlike getStages(), which hydrates them).
        $this->entityManager->clear();

        $this->repository->getStageGeometry($tripId, 2);

        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();
        self::assertArrayNotHasKey(StageEntity::class, $identityMap);
    }

    #[Test]
    public function returnsNullForUnknownTrip(): void
    {
        self::assertNull($this->repository->getStageGeometry(Uuid::v7()->toRfc4122(), 1));
    }

    #[Test]
    public function returnsNullForInvalidTripId(): void
    {
        self::assertNull($this->repository->getStageGeometry('not-a-uuid', 1));
    }

    #[Test]
    public function returnsNullForUnknownDay(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->seedTrip($tripId);

        $this->entityManager->clear();

        self::assertNull($this->repository->getStageGeometry($tripId, 99));
    }

    #[Test]
    public function returnsNullForEmptyGeometry(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        // Day 1 has no geometry (StageDto default is []).
        $this->repository->storeStages($tripId, [
            new StageDto(
                tripId: $tripId,
                dayNumber: 1,
                distance: 10.0,
                elevation: 50.0,
                startPoint: new Coordinate(48.0, 2.0),
                endPoint: new Coordinate(48.1, 2.1),
            ),
        ]);

        $this->entityManager->clear();

        self::assertNull($this->repository->getStageGeometry($tripId, 1));
    }

    private function seedTrip(string $tripId): void
    {
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [
            new StageDto(
                tripId: $tripId,
                dayNumber: 2,
                distance: 40.0,
                elevation: 200.0,
                startPoint: new Coordinate(48.0, 2.0),
                endPoint: new Coordinate(48.2, 2.2),
                geometry: [
                    new Coordinate(48.0, 2.0, 100.0),
                    new Coordinate(48.1, 2.1, 110.0),
                    new Coordinate(48.2, 2.2, 120.0),
                ],
            ),
        ]);
    }
}
