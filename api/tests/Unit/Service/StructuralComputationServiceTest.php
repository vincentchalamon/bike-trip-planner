<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\SourceType;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\StructuralComputationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StructuralComputationServiceTest extends TestCase
{
    #[Test]
    public function pacingPathDelegatesToPacingEngineWithProfileSettings(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $request = new TripRequest();
        $request->maxDistancePerDay = 45.0;
        $request->fatigueFactor = 0.85;
        $request->elevationPenalty = 40.0;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn(null);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(142.0);

        $expected = [
            new Stage(tripId: 'trip-1', dayNumber: 1, distance: 40.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
            new Stage(tripId: 'trip-1', dayNumber: 2, distance: 40.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
        ];

        $pacingEngine = $this->createMock(PacingEngineInterface::class);
        $pacingEngine->expects($this->once())
            ->method('generateStages')
            ->with(
                'trip-1',
                $this->anything(),
                4, // ceil(142/45)
                142.0,
                0.85,
                40.0,
                null, // rawPoints null when no full-resolution data
                45.0,
            )
            ->willReturn($expected);

        $service = new StructuralComputationService(
            $tripStateManager,
            $distanceCalculator,
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $pacingEngine,
        );

        self::assertSame($expected, $service->generateStages('trip-1', $request));
    }

    #[Test]
    public function pacingPathUsesDateRangeForNumberOfDays(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-01');
        $request->endDate = new \DateTimeImmutable('2026-07-03'); // 3 days inclusive

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::GPX_UPLOAD->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(200.0);

        $pacingEngine = $this->createMock(PacingEngineInterface::class);
        $pacingEngine->expects($this->once())
            ->method('generateStages')
            ->with(
                'trip-1',
                $this->anything(),
                3, // from the date range, not the distance
                200.0,
                $this->anything(),
                $this->anything(),
                $this->isArray(), // rawPoints passed through
                $this->anything(),
            )
            ->willReturn([
                new Stage(tripId: 'trip-1', dayNumber: 1, distance: 70.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
            ]);

        $service = new StructuralComputationService(
            $tripStateManager,
            $distanceCalculator,
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $pacingEngine,
        );

        $service->generateStages('trip-1', $request);
    }

    #[Test]
    public function collectionPathBuildsOneStagePerTrack(): void
    {
        $request = new TripRequest();

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_COLLECTION->value);
        $tripStateManager->method('getTracksData')->willReturn([
            // Track 1
            [
                ['lat' => 48.0, 'lon' => 2.0, 'ele' => 10.0],
                ['lat' => 48.5, 'lon' => 2.5, 'ele' => 20.0],
            ],
            // Track 2
            [
                ['lat' => 49.0, 'lon' => 3.0, 'ele' => 30.0],
                ['lat' => 49.5, 'lon' => 3.5, 'ele' => 40.0],
            ],
        ]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(60.0);

        $elevationCalculator = $this->createStub(ElevationCalculatorInterface::class);
        $elevationCalculator->method('calculateTotalAscent')->willReturn(120.0);
        $elevationCalculator->method('calculateTotalDescent')->willReturn(80.0);

        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        // The pacing engine must NOT be used on the collection path.
        $pacingEngine = $this->createMock(PacingEngineInterface::class);
        $pacingEngine->expects($this->never())->method('generateStages');

        $service = new StructuralComputationService(
            $tripStateManager,
            $distanceCalculator,
            $elevationCalculator,
            $routeSimplifier,
            $pacingEngine,
        );

        $stages = $service->generateStages('trip-1', $request);

        self::assertCount(2, $stages);
        self::assertSame(1, $stages[0]->dayNumber);
        self::assertSame(2, $stages[1]->dayNumber);
        self::assertSame(60.0, $stages[0]->distance);
        self::assertSame(120.0, $stages[0]->elevation);
        self::assertSame(80.0, $stages[0]->elevationLoss);
        // start/end derived from the track's first/last points
        self::assertSame(48.0, $stages[0]->startPoint->lat);
        self::assertSame(48.5, $stages[0]->endPoint->lat);
        self::assertSame(49.0, $stages[1]->startPoint->lat);
        self::assertSame(49.5, $stages[1]->endPoint->lat);
    }

    #[Test]
    public function collectionPathSkipsEmptyTracks(): void
    {
        $request = new TripRequest();

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_COLLECTION->value);
        $tripStateManager->method('getTracksData')->willReturn([
            [],
            [
                ['lat' => 49.0, 'lon' => 3.0, 'ele' => 30.0],
                ['lat' => 49.5, 'lon' => 3.5, 'ele' => 40.0],
            ],
        ]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(30.0);
        $elevationCalculator = $this->createStub(ElevationCalculatorInterface::class);
        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        $service = new StructuralComputationService(
            $tripStateManager,
            $distanceCalculator,
            $elevationCalculator,
            $routeSimplifier,
            $this->createStub(PacingEngineInterface::class),
        );

        $stages = $service->generateStages('trip-1', $request);

        self::assertCount(1, $stages);
        // dayNumber keeps the original track index (i + 1), the empty track is skipped.
        self::assertSame(2, $stages[0]->dayNumber);
    }

    #[Test]
    public function returnsEmptyWhenNoDecimatedPoints(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $service = new StructuralComputationService(
            $tripStateManager,
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $this->createStub(PacingEngineInterface::class),
        );

        self::assertSame([], $service->generateStages('trip-1', new TripRequest()));
    }

    #[Test]
    public function serializeStagesForEventRoundsAndFlattensCoordinates(): void
    {
        $service = new StructuralComputationService(
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $this->createStub(PacingEngineInterface::class),
        );

        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.456,
            elevation: 512.7,
            startPoint: new Coordinate(48.8566, 2.3522, 35.0),
            endPoint: new Coordinate(49.0, 2.5, 50.0),
            geometry: [new Coordinate(48.8566, 2.3522, 35.0)],
            label: 'Etape 1',
            elevationLoss: 200.9,
        );

        $payload = $service->serializeStagesForEvent([$stage]);

        self::assertCount(1, $payload);
        self::assertSame(1, $payload[0]['dayNumber']);
        self::assertSame(80.5, $payload[0]['distance']);
        self::assertSame(512, $payload[0]['elevation']);
        self::assertSame(200, $payload[0]['elevationLoss']);
        self::assertSame('Etape 1', $payload[0]['label']);
        self::assertSame(['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0], $payload[0]['startPoint']);
        self::assertSame([['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0]], $payload[0]['geometry']);
    }
}
