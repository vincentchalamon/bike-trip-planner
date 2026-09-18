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
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * {@see DoctrineTripRequestRepository::storeStages()} reconciles the persisted rows
 * against the incoming identifiers instead of deleting and re-inserting them, so a stage
 * keeps its identity across every write (ADR-066).
 *
 * The Doctrine implementation is resolved explicitly: `config/services.php` aliases
 * {@see TripRequestRepositoryInterface} to the Redis implementation in the `test`
 * environment, so the interface would never exercise the SQL path under test here.
 */
final class DoctrineStageReconciliationTest extends KernelTestCase
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
    public function identifiersSurviveAnUnchangedRewrite(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->persistedIds($tripId);

        $this->repository->storeStages($tripId, $this->repository->getStages($tripId) ?? []);

        self::assertSame($before, $this->persistedIds($tripId));
    }

    #[Test]
    public function aMoveKeepsIdentifiersAndRenumbersPositions(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        [$first, $second, $third] = $stages;

        // Move the third stage to the front, as StageMoveProcessor does.
        $this->repository->storeStages($tripId, [$third, $first, $second]);

        self::assertSame([$third->id, $first->id, $second->id], $this->persistedIds($tripId));
        self::assertSame([0, 1, 2], $this->persistedPositions($tripId));
    }

    /**
     * The failure this pins is invisible to the eye: with a position-aligned map, the
     * PostGIS fractions would be re-applied in the old order after a move, so each stage
     * would silently inherit its neighbour's value.
     */
    #[Test]
    public function theOnCycleNetworkFractionFollowsTheStageNotThePosition(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        [$first, $second, $third] = $stages;

        $this->setOnCycleNetwork($first->id, 0.1);
        $this->setOnCycleNetwork($second->id, 0.2);
        $this->setOnCycleNetwork($third->id, 0.3);

        $this->repository->storeStages($tripId, [$third, $first, $second]);

        self::assertSame(0.3, $this->onCycleNetworkOf($third->id));
        self::assertSame(0.1, $this->onCycleNetworkOf($first->id));
        self::assertSame(0.2, $this->onCycleNetworkOf($second->id));
    }

    #[Test]
    public function aDeletionRemovesOnlyTheMissingStage(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        [$first, , $third] = $stages;

        $this->repository->storeStages($tripId, [$first, $third]);

        self::assertSame([$first->id, $third->id], $this->persistedIds($tripId));
    }

    #[Test]
    public function anInsertionKeepsTheSurroundingIdentifiers(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        [$first, $second, $third] = $stages;
        $inserted = $this->stage($tripId, 2);

        $this->repository->storeStages($tripId, [$first, $inserted, $second, $third]);

        self::assertSame(
            [$first->id, $inserted->id, $second->id, $third->id],
            $this->persistedIds($tripId),
        );
    }

    /**
     * A pacing regeneration builds a brand new list: the identifier sets are disjoint, so
     * nothing is reconciled and every row is replaced. Handing the client a 404 on an old
     * identifier is the intended behaviour — those are not the same stages any more.
     */
    #[Test]
    public function aRegenerationReplacesEveryIdentifierAndLeavesNoOrphan(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->persistedIds($tripId);

        $this->repository->storeStages($tripId, [$this->stage($tripId, 1), $this->stage($tripId, 2)]);

        $after = $this->persistedIds($tripId);
        self::assertCount(2, $after);
        self::assertSame([], array_intersect($before, $after));
        self::assertSame(2, $this->countRows($tripId));
    }

    #[Test]
    public function anUnknownIdentifierIsInsertedWithoutTouchingAnotherTrip(): void
    {
        $otherTripId = $this->seedTrip();
        $otherIds = $this->persistedIds($otherTripId);

        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        $stages[] = $this->stage($tripId, 4);

        $this->repository->storeStages($tripId, $stages);

        self::assertCount(4, $this->persistedIds($tripId));
        self::assertSame($otherIds, $this->persistedIds($otherTripId));
    }

    #[Test]
    public function duplicateIdentifiersFailWhereTheCauseIsVisible(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/duplicate identifiers/');

        $this->repository->storeStages($tripId, [$stages[0], $stages[0]]);
    }

    /**
     * The structural version is what a message's generation is compared against, and what
     * PR3 will hand out as an ETag. It has to move on every write of the collection —
     * including the ones a worker performs when the pacing is regenerated, which no
     * HTTP-side counter would have seen.
     */
    #[Test]
    public function everyWriteOfTheCollectionBumpsTheVersion(): void
    {
        $tripId = $this->seedTrip();
        $before = $this->repository->getVersion($tripId);
        self::assertNotNull($before);

        $this->repository->storeStages($tripId, $this->repository->getStages($tripId) ?? []);

        self::assertSame($before + 1, $this->repository->getVersion($tripId));
    }

    /** A targeted enrichment write is not a structural change and must leave it alone. */
    #[Test]
    public function aTargetedEnrichmentWriteDoesNotBumpTheVersion(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        $before = $this->repository->getVersion($tripId);

        $this->repository->updateStageLabels($tripId, $stages[0]->id, 'Lyon', 'Vienne');

        self::assertSame($before, $this->repository->getVersion($tripId));
    }

    /** @return list<string> */
    private function persistedIds(string $tripId): array
    {
        return array_map(
            static fn (StageEntity $stage): string => $stage->getId()->toRfc4122(),
            $this->persistedStages($tripId),
        );
    }

    /** @return list<int> */
    private function persistedPositions(string $tripId): array
    {
        return array_map(
            static fn (StageEntity $stage): int => $stage->getPosition(),
            $this->persistedStages($tripId),
        );
    }

    /** @return list<StageEntity> */
    private function persistedStages(string $tripId): array
    {
        $this->entityManager->clear();

        /** @var list<StageEntity> $stages */
        $stages = $this->entityManager
            ->createQuery('SELECT s FROM App\Entity\Stage s WHERE s.trip = :trip ORDER BY s.position ASC')
            ->setParameter('trip', Uuid::fromString($tripId))
            ->getResult();

        return $stages;
    }

    private function countRows(string $tripId): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM stage WHERE trip_id = :trip',
            ['trip' => $tripId],
        );
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function setOnCycleNetwork(string $stageId, float $fraction): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stage SET on_cycle_network = :fraction WHERE id = :id',
            ['fraction' => $fraction, 'id' => $stageId],
        );
    }

    private function onCycleNetworkOf(string $stageId): float
    {
        $fraction = $this->entityManager->getConnection()->fetchOne(
            'SELECT on_cycle_network FROM stage WHERE id = :id',
            ['id' => $stageId],
        );
        \assert(is_numeric($fraction));

        return (float) $fraction;
    }

    private function seedTrip(): string
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

    private function stage(string $tripId, int $dayNumber): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 40.0 + $dayNumber,
            elevation: 200.0,
            startPoint: new Coordinate(48.0 + $dayNumber, 2.0),
            endPoint: new Coordinate(48.2 + $dayNumber, 2.2),
        );
    }
}
