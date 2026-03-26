<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use DateTimeImmutable;
use stdClass;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageUpdateProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class StageUpdateProcessorTest extends TestCase
{
    /** @var list<array{lat: float, lon: float, ele: float}> */
    private array $decimatedPointsRaw;

    /** @var list<Coordinate> */
    private array $decimatedPoints;

    protected function setUp(): void
    {
        // 5 points: 0→1→2→3→4 (indices match for simplicity)
        $this->decimatedPointsRaw = [
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.1, 'lon' => 2.1, 'ele' => 10.0],
            ['lat' => 48.2, 'lon' => 2.2, 'ele' => 20.0],
            ['lat' => 48.3, 'lon' => 2.3, 'ele' => 30.0],
            ['lat' => 48.4, 'lon' => 2.4, 'ele' => 40.0],
        ];

        $this->decimatedPoints = array_map(
            static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
            $this->decimatedPointsRaw,
        );
    }

    private function makeUnlockedRequest(): TripRequest
    {
        $request = new TripRequest();
        $request->startDate = new DateTimeImmutable('+30 days');

        return $request;
    }

    #[Test]
    public function shorteningMiddleStageReportsKmToNextStage(): void
    {
        // 3 stages: [0→1], [1→3], [3→4]
        // Shorten stage 0 to end at point 0.5 → remaining [0.5→1] goes to stage 1
        // Stage 1 absorbs: [0.5→3], stage 2 stays unchanged
        $p = $this->decimatedPoints;

        $stages = [
            new Stage(tripId: 't', dayNumber: 1, distance: 30.0, elevation: 10.0, startPoint: $p[0], endPoint: $p[1]),
            new Stage(tripId: 't', dayNumber: 2, distance: 60.0, elevation: 20.0, startPoint: $p[1], endPoint: $p[3]),
            new Stage(tripId: 't', dayNumber: 3, distance: 30.0, elevation: 10.0, startPoint: $p[3], endPoint: $p[4]),
        ];

        $splitPoint = new Coordinate(48.05, 2.05, 5.0);
        $remaining = [$splitPoint, $p[1], $p[2], $p[3], $p[4]];

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('splitAtDistance')->willReturn([
            [$p[0], $splitPoint],
            $remaining,
            15.0,
        ]);
        $distanceCalculator->method('findClosestIndex')->willReturnCallback(
            function (array $points, Coordinate $target): int {
                // Map coordinates to decimated indices
                foreach ($this->decimatedPoints as $i => $p) {
                    if ($p->lat === $target->lat && $p->lon === $target->lon) {
                        return $i;
                    }
                }


                return 0;
            },
        );
        $distanceCalculator->method('calculateTotalDistance')->willReturn(15.0);

        $elevationCalculator = $this->createStub(ElevationCalculatorInterface::class);
        $elevationCalculator->method('calculateTotalAscent')->willReturn(5.0);
        $elevationCalculator->method('calculateTotalDescent')->willReturn(3.0);

        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        $storedStages = null;
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getDecimatedPoints')->willReturn($this->decimatedPointsRaw);
        $tripStateManager->method('getRequest')->willReturn($this->makeUnlockedRequest());
        $tripStateManager->method('storeStages')->willReturnCallback(
            static function (string $tripId, array $stages) use (&$storedStages): void {
                $storedStages = $stages;
            },
        );

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $objectMapper = $this->createStub(ObjectMapperInterface::class);
        $objectMapper->method('map')->willReturn(new StageResponse());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        $processor = new StageUpdateProcessor(
            $tripStateManager,
            $messageBus,
            $distanceCalculator,
            $elevationCalculator,
            $routeSimplifier,
            $objectMapper,
            $generationTracker,
            new TripLocker(),
        );

        $request = new StageRequest();
        $request->distance = 15.0;

        $processor->process($request, new Patch(), ['tripId' => 't', 'index' => 0]);

        self::assertNotNull($storedStages);
        self::assertCount(3, $storedStages, 'Stage count should remain 3');

        // Stage 1 (next) should have been recalculated
        self::assertNotSame($p[1], $storedStages[1]->startPoint, 'Stage 1 startPoint should have changed');

        // Stage 2 should remain unchanged
        self::assertSame($p[3], $storedStages[2]->startPoint, 'Stage 2 startPoint should be unchanged');
        self::assertSame($p[4], $storedStages[2]->endPoint, 'Stage 2 endPoint should be unchanged');
    }

    #[Test]
    public function shorteningLastStageCreatesNewStage(): void
    {
        // 2 stages: [0→2], [2→4]
        // Shorten stage 1 (last) → remaining points become a new stage 3
        $p = $this->decimatedPoints;

        $stages = [
            new Stage(tripId: 't', dayNumber: 1, distance: 60.0, elevation: 20.0, startPoint: $p[0], endPoint: $p[2]),
            new Stage(tripId: 't', dayNumber: 2, distance: 60.0, elevation: 20.0, startPoint: $p[2], endPoint: $p[4]),
        ];

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('splitAtDistance')->willReturn([
            [$p[2], $p[3]],          // stagePoints (shortened)
            [$p[3], $p[4]],          // remaining
            30.0,
        ]);
        $distanceCalculator->method('findClosestIndex')->willReturnCallback(
            function (array $points, Coordinate $target): int {
                foreach ($this->decimatedPoints as $i => $p) {
                    if ($p->lat === $target->lat && $p->lon === $target->lon) {
                        return $i;
                    }
                }

                return 0;
            },
        );
        $distanceCalculator->method('calculateTotalDistance')->willReturn(30.0);

        $elevationCalculator = $this->createStub(ElevationCalculatorInterface::class);
        $elevationCalculator->method('calculateTotalAscent')->willReturn(10.0);
        $elevationCalculator->method('calculateTotalDescent')->willReturn(5.0);

        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        $storedStages = null;
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getDecimatedPoints')->willReturn($this->decimatedPointsRaw);
        $tripStateManager->method('getRequest')->willReturn($this->makeUnlockedRequest());
        $tripStateManager->method('storeStages')->willReturnCallback(
            static function (string $tripId, array $stages) use (&$storedStages): void {
                $storedStages = $stages;
            },
        );

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $objectMapper = $this->createStub(ObjectMapperInterface::class);
        $objectMapper->method('map')->willReturn(new StageResponse());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        $processor = new StageUpdateProcessor(
            $tripStateManager,
            $messageBus,
            $distanceCalculator,
            $elevationCalculator,
            $routeSimplifier,
            $objectMapper,
            $generationTracker,
            new TripLocker(),
        );

        $request = new StageRequest();
        $request->distance = 30.0;

        $processor->process($request, new Patch(), ['tripId' => 't', 'index' => 1]);

        self::assertNotNull($storedStages);
        self::assertCount(3, $storedStages, 'A new stage should have been created');

        $newStage = $storedStages[2];
        self::assertSame(3, $newStage->dayNumber, 'New stage should be day 3');
        self::assertSame($p[3]->lat, $newStage->startPoint->lat, 'New stage should start at remaining[0]');
        self::assertSame($p[4]->lat, $newStage->endPoint->lat, 'New stage should end at remaining[-1]');
    }

    #[Test]
    public function shorteningMiddleStageWithCollapsedIndexKeepsStagesContiguous(): void
    {
        // Simulate findClosestIndex snapping remaining[0] to the same index as nextEndIdx,
        // producing an empty/single-point slice → fallback branch (count($nextPoints) < 2)
        $p = $this->decimatedPoints;

        $stages = [
            new Stage(tripId: 't', dayNumber: 1, distance: 30.0, elevation: 10.0, startPoint: $p[0], endPoint: $p[1]),
            new Stage(tripId: 't', dayNumber: 2, distance: 60.0, elevation: 20.0, startPoint: $p[1], endPoint: $p[3]),
        ];

        $splitPoint = new Coordinate(48.05, 2.05, 5.0);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        // Return 2 stagePoints (passes the count >= 2 guard) and 2 remaining points
        $distanceCalculator->method('splitAtDistance')->willReturn([[$p[0], $splitPoint], [$splitPoint, $p[2]], 15.0]);
        // Both calls return the same index (2) → array_slice($decimatedPoints, 2, 2-2+1) = 1 element → fallback
        $distanceCalculator->method('findClosestIndex')->willReturn(2);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(15.0);

        $elevationCalculator = $this->createStub(ElevationCalculatorInterface::class);
        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        $storedStages = null;
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getDecimatedPoints')->willReturn($this->decimatedPointsRaw);
        $tripStateManager->method('getRequest')->willReturn($this->makeUnlockedRequest());
        $tripStateManager->method('storeStages')->willReturnCallback(
            static function (string $tripId, array $stages) use (&$storedStages): void {
                $storedStages = $stages;
            },
        );

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $objectMapper = $this->createStub(ObjectMapperInterface::class);
        $objectMapper->method('map')->willReturn(new StageResponse());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        $processor = new StageUpdateProcessor(
            $tripStateManager,
            $messageBus,
            $distanceCalculator,
            $elevationCalculator,
            $routeSimplifier,
            $objectMapper,
            $generationTracker,
            new TripLocker(),
        );

        $request = new StageRequest();
        $request->distance = 0.0;

        $processor->process($request, new Patch(), ['tripId' => 't', 'index' => 0]);

        self::assertNotNull($storedStages);
        // Fallback: stage 1 startPoint must equal stage 0 endPoint to stay contiguous
        self::assertSame($storedStages[0]->endPoint, $storedStages[1]->startPoint);
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $p = $this->decimatedPoints;

        $stages = [
            new Stage(tripId: 't', dayNumber: 1, distance: 30.0, elevation: 10.0, startPoint: $p[0], endPoint: $p[1]),
        ];

        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getDecimatedPoints')->willReturn($this->decimatedPointsRaw);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);

        $processor = new StageUpdateProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            $this->createStub(TripGenerationTrackerInterface::class),
            new TripLocker(),
        );

        try {
            $processor->process(new StageRequest(), new Patch(), ['tripId' => 't', 'index' => 0]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
