<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\Service\StructuralComputationService;
use App\Service\TripBootstrapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Storing stages replaces the trip's collection, so an empty pacing must never be stored over
 * existing stages (#1405).
 */
final class TripBootstrapperTest extends TestCase
{
    #[Test]
    public function anEmptyPacingNeverReplacesExistingStages(): void
    {
        $point = new Coordinate(45.0, 5.0, 200.0);
        $stageStore = $this->createMock(TripStageStoreInterface::class);
        $stageStore->method('getStages')->willReturn([
            new Stage(tripId: 'trip-1', dayNumber: 1, distance: 10.0, elevation: 0.0, startPoint: $point, endPoint: $point),
            new Stage(tripId: 'trip-1', dayNumber: 2, distance: 10.0, elevation: 0.0, startPoint: $point, endPoint: $point),
        ]);
        $stageStore->expects($this->never())->method('storeStages');

        $this->expectException(\LogicException::class);

        $this->bootstrapper($stageStore)->storeStages('trip-1', new TripRequest());
    }

    #[Test]
    public function anEmptyPacingOfATripWithoutStagesIsStillStored(): void
    {
        $stageStore = $this->createMock(TripStageStoreInterface::class);
        $stageStore->method('getStages')->willReturn(null);
        $stageStore->expects($this->once())->method('storeStages')->with('trip-1', []);

        $this->bootstrapper($stageStore)->storeStages('trip-1', new TripRequest());
    }

    private function bootstrapper(TripStageStoreInterface $stageStore): TripBootstrapper
    {
        // No route anywhere: neither the transient points nor the stages' own geometry.
        $points = $this->createStub(TransientTripPointsStoreInterface::class);
        $points->method('getDecimatedPoints')->willReturn(null);

        $structural = new StructuralComputationService(
            $this->createStub(TripRequestRepositoryInterface::class),
            $points,
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $this->createStub(PacingEngineInterface::class),
            $stageStore,
        );

        return new TripBootstrapper(
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createStub(ComputationTrackerInterface::class),
            $this->createStub(TripGenerationTrackerInterface::class),
            $points,
            $this->createStub(RouteSimplifierInterface::class),
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(TripUpdatePublisherInterface::class),
            $stageStore,
            $structural,
        );
    }
}
