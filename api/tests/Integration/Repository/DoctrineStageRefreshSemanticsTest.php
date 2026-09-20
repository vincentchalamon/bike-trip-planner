<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\Stage as StageEntity;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Characterisation of what a stage re-read actually sees once the caller has already
 * hydrated the trip — the premise the whole locking design of the stage write path
 * rests on (lot A).
 *
 * Every processor reads the stages, mutates them in memory, then writes them back. To
 * make that sequence safe, the re-read performed inside the critical section must
 * observe writes committed by another process in between. These tests measure whether
 * it does, rather than assuming the ORM behaves a particular way.
 *
 * The concurrent writer is simulated with raw SQL on the DBAL connection: it bypasses
 * the unit of work exactly as a Messenger worker in another container would.
 *
 * The Doctrine implementation is resolved explicitly, never through
 * {@see TripRequestRepositoryInterface}: `config/services.php` aliases that interface to
 * the Redis implementation in the `test` environment, so the interface would exercise a
 * blob in Redis and never touch SQL.
 */
final class DoctrineStageRefreshSemanticsTest extends KernelTestCase
{
    use ResetDatabase;

    private DoctrineTripRequestRepository $repository;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var DoctrineTripRequestRepository $repository */
        $repository = $container->get(DoctrineTripRequestRepository::class);
        $this->repository = $repository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    #[Test]
    public function aPlainReReadServesTheStaleIdentityMap(): void
    {
        $tripId = $this->seedTrip();
        $this->repository->getStages($tripId);

        $this->writeWeatherOutsideTheUnitOfWork($tripId, dayNumber: 1);

        self::assertNull(
            $this->queryStages($tripId, refresh: false)[0]->getWeather(),
            'A re-read without HINT_REFRESH is served from the identity map, so the concurrent write is invisible.',
        );
    }

    #[Test]
    public function hintRefreshObservesAConcurrentColumnWrite(): void
    {
        $tripId = $this->seedTrip();
        $this->repository->getStages($tripId);

        $this->writeWeatherOutsideTheUnitOfWork($tripId, dayNumber: 1);

        $weather = $this->queryStages($tripId, refresh: true)[0]->getWeather();

        self::assertIsArray($weather);
        self::assertSame('concurrent', $weather['icon'] ?? null);
    }

    #[Test]
    public function hintRefreshObservesAConcurrentInsert(): void
    {
        $tripId = $this->seedTrip();
        $this->repository->getStages($tripId);

        $this->insertStageOutsideTheUnitOfWork($tripId, dayNumber: 3, position: 2);

        self::assertCount(3, $this->queryStages($tripId, refresh: true));
    }

    #[Test]
    public function hintRefreshObservesAConcurrentDelete(): void
    {
        $tripId = $this->seedTrip();
        $this->repository->getStages($tripId);

        $this->deleteStageOutsideTheUnitOfWork($tripId, dayNumber: 2);

        self::assertCount(1, $this->queryStages($tripId, refresh: true));
    }

    /**
     * The reason the reconciliation must not be built on the owning collection: it was
     * initialised by the caller's read and is never re-synchronised, so a stage deleted
     * (or inserted) meanwhile stays invisible to it even under HINT_REFRESH.
     */
    #[Test]
    public function theOwningCollectionStaysStaleEvenAfterHintRefresh(): void
    {
        $tripId = $this->seedTrip();
        $this->repository->getStages($tripId);

        $this->deleteStageOutsideTheUnitOfWork($tripId, dayNumber: 2);
        $this->queryStages($tripId, refresh: true);

        $trip = $this->entityManager->find(TripRequest::class, Uuid::fromString($tripId));
        self::assertInstanceOf(TripRequest::class, $trip);

        self::assertCount(
            2,
            $trip->stages,
            'The already-initialised PersistentCollection still holds the deleted stage.',
        );
    }

    /**
     * @return list<StageEntity>
     */
    private function queryStages(string $tripId, bool $refresh): array
    {
        $query = $this->entityManager
            ->createQuery('SELECT s FROM App\Entity\Stage s WHERE s.trip = :trip ORDER BY s.position ASC')
            ->setParameter('trip', Uuid::fromString($tripId));

        if ($refresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }

        /** @var list<StageEntity> $stages */
        $stages = $query->getResult();

        return $stages;
    }

    private function writeWeatherOutsideTheUnitOfWork(string $tripId, int $dayNumber): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stage SET weather = :weather WHERE trip_id = :trip AND day_number = :dayNumber',
            [
                'weather' => json_encode(['icon' => 'concurrent'], \JSON_THROW_ON_ERROR),
                'trip' => $tripId,
                'dayNumber' => $dayNumber,
            ],
        );
    }

    private function insertStageOutsideTheUnitOfWork(string $tripId, int $dayNumber, int $position): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO stage (id, trip_id, position, day_number, distance, elevation, elevation_loss,
                                   start_lat, start_lon, start_ele, end_lat, end_lon, end_ele,
                                   geometry, is_rest_day, alerts_by_group, pois, accommodations, on_cycle_network)
                VALUES (:id, :trip, :position, :dayNumber, 10, 0, 0, 48, 2, 0, 48, 2, 0,
                        '[]', false, '{}', '[]', '[]', 0)
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'trip' => $tripId,
                'position' => $position,
                'dayNumber' => $dayNumber,
            ],
        );
    }

    private function deleteStageOutsideTheUnitOfWork(string $tripId, int $dayNumber): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM stage WHERE trip_id = :trip AND day_number = :dayNumber',
            ['trip' => $tripId, 'dayNumber' => $dayNumber],
        );
    }

    private function seedTrip(): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [
            $this->stage($tripId, 1),
            $this->stage($tripId, 2),
        ]);

        return $tripId;
    }

    private function stage(string $tripId, int $dayNumber): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 40.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
        );
    }
}
