<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Mapper\StageArrayMapper;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\DoctrineTripStageStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The PostGIS route metrics are geometry-derived, so a write that leaves the geometry alone
 * reuses the persisted values instead of re-scanning (#787). "Alone" is judged against what
 * the database hands back, and the geometry column did not hand back what was written: it was
 * encoded without JSON_PRESERVE_ZERO_FRACTION, so a coordinate of `2.0` read back as the int
 * `2`. Compared strictly, every route with one integral coordinate looked moved on every write.
 */
#[ResetDatabase]
final class DoctrineStageGeometrySignatureTest extends KernelTestCase
{
    #[Test]
    public function aWriteThatLeavesAnIntegralGeometryAloneReusesThePersistedMetrics(): void
    {
        $cycleRoute = $this->createMock(CycleRouteRepositoryInterface::class);
        $cycleRoute->expects(self::once())->method('onNetworkFractions')->willReturn([0.42]);
        $coverage = $this->createMock(CoverageRepositoryInterface::class);
        $coverage->expects(self::once())->method('isRouteOutOfZone')->willReturn(true);

        [$store, $tripId] = $this->storeWithTrip($cycleRoute, $coverage);

        $store->storeStages($tripId, [$this->integralStage($tripId)]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // A non-geometry edit, written back from what the store reads.
        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);
        $stages[0]->label = 'Renamed';
        $store->storeStages($tripId, $stages);

        $reread = $store->getStages($tripId);
        self::assertNotNull($reread);
        self::assertSame('Renamed', $reread[0]->label);
        self::assertEqualsWithDelta(0.42, $reread[0]->onCycleNetwork, 0.0001);
    }

    /**
     * The same trap one level down: the unit of work compares the geometry it loaded (ints)
     * with the one assigned from the DTO (floats) strictly, so rewriting an unchanged stage
     * shipped the whole geometry column back to the database.
     */
    #[Test]
    public function rewritingAnUnchangedIntegralGeometryDoesNotWriteTheColumnAgain(): void
    {
        $cycleRoute = $this->createStub(CycleRouteRepositoryInterface::class);
        $cycleRoute->method('onNetworkFractions')->willReturn([0.42]);
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(false);

        [$store, $tripId] = $this->storeWithTrip($cycleRoute, $coverage);

        $store->storeStages($tripId, [$this->integralStage($tripId)]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $store->storeStages($tripId, $stages);

        foreach ($queries->getData()['default'] ?? [] as $query) {
            $sql = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            if (str_starts_with($sql, 'UPDATE stage')) {
                self::assertStringNotContainsString('geometry', $sql);
            }
        }
    }

    #[Test]
    public function movingAnIntegralCoordinateStillCountsAsAGeometryChange(): void
    {
        $cycleRoute = $this->createMock(CycleRouteRepositoryInterface::class);
        $cycleRoute->expects(self::exactly(2))->method('onNetworkFractions')->willReturn([0.1]);
        $coverage = $this->createMock(CoverageRepositoryInterface::class);
        $coverage->expects(self::exactly(2))->method('isRouteOutOfZone')->willReturn(false);

        [$store, $tripId] = $this->storeWithTrip($cycleRoute, $coverage);

        $store->storeStages($tripId, [$this->integralStage($tripId)]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);
        $stages[0]->endPoint = new Coordinate(49.0, 3.0, 0.0);
        $store->storeStages($tripId, $stages);
    }

    /**
     * @return array{DoctrineTripStageStore, string}
     */
    private function storeWithTrip(CycleRouteRepositoryInterface $cycleRoute, CoverageRepositoryInterface $coverage): array
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var StageArrayMapper $mapper */
        $mapper = $container->get(StageArrayMapper::class);
        /** @var DoctrineTripRequestRepository $trips */
        $trips = $container->get(DoctrineTripRequestRepository::class);

        $tripId = Uuid::v7()->toRfc4122();
        $trips->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));

        return [new DoctrineTripStageStore($entityManager, $cycleRoute, $coverage, $mapper), $tripId];
    }

    private function integralStage(string $tripId): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 55.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.0, 2.0, 0.0),
            endPoint: new Coordinate(49.0, 2.0, 0.0),
            geometry: [
                new Coordinate(48.0, 2.0, 0.0),
                new Coordinate(48.5, 2.0, 0.0),
                new Coordinate(49.0, 2.0, 0.0),
            ],
        );
    }
}
