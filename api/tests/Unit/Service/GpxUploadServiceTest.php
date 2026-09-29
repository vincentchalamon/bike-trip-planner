<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Message\GenerateStages;
use App\Message\AnalyzeTerrain;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Message\BelongsToATripGeneration;
use App\Mercure\MercureEventType;
use App\Mercure\ProgressPublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\RouteParser\GpxRouteParserInterface;
use App\Service\EnrichmentMessageFactory;
use App\Service\GpxUploadService;
use App\Service\StructuralComputationService;
use App\Service\TripAnalysisDispatcher;
use App\Service\TripBootstrapper;
use App\State\TripLocker;
use Symfony\Component\Clock\NativeClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pins what a GPX upload writes and publishes, in order: the events a client receives are the
 * contract (ADR-072), so a refactoring of the creation path must leave this sequence untouched.
 */
final class GpxUploadServiceTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $events = [];

    #[Test]
    public function anUploadWritesAndPublishesInAFixedOrder(): void
    {
        $service = $this->service([$this->stage(1), $this->stage(2)]);
        $user = new User('owner@test.com');
        $request = new TripRequest();

        $result = $service->createTrip([new Coordinate(45.0, 5.0, 100.0), new Coordinate(45.1, 5.1, 200.0)], 'My Route', $request, 'fr', $user);

        self::assertSame($user, $request->user);
        self::assertSame([
            'initializeTrip:fr',
            'initializeComputations:'.\count(ComputationName::pipeline()),
            'storeRawPoints:2',
            'storeSourceType:gpx_upload',
            'storeTitle:My Route',
            'storeDecimatedPoints:2',
            'publish:route_parsed',
            'storeStages:2',
            'storeStatus:ready',
            'publish:stages_computed',
            'publishComputationStepCompleted:stages:3/14/1',
        ], array_values(array_filter($this->log, static fn (string $entry): bool => !str_starts_with($entry, 'dispatch:'))));

        self::assertNotContains('dispatch:'.GenerateStages::class, $this->log);
        self::assertContains('dispatch:'.AnalyzeTerrain::class, $this->log);
        $lastWrite = array_search('publishComputationStepCompleted:stages:3/14/1', $this->log, true);
        $firstDispatch = array_key_first(array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'dispatch:')));
        self::assertGreaterThan($lastWrite, $firstDispatch, 'The enrichments are dispatched after the structural events.');
        self::assertNotEmpty($this->dispatchedGenerations);
        self::assertSame([2], array_values(array_unique($this->dispatchedGenerations)), 'The enrichments carry the generation the stage write produced.');

        self::assertSame([
            'totalDistance' => 123.5,
            'totalElevation' => 800,
            'totalElevationLoss' => 700,
            'sourceType' => 'gpx_upload',
            'title' => 'My Route',
        ], $this->events[0][1]);
        $publishedStages = $this->events[1][1]['stages'] ?? null;
        self::assertIsArray($publishedStages);
        self::assertCount(2, $publishedStages);

        self::assertSame($result['tripId'], $this->tripIds()[0]);
        self::assertSame(123.5, $result['totalDistance']);
        self::assertSame(800, $result['totalElevation']);
        self::assertSame(700, $result['totalElevationLoss']);
        self::assertSame('ready', $result['status']);
        self::assertFalse($result['isLocked']);
        self::assertSame($publishedStages, $result['stages']);
        self::assertSame('done', $result['computationStatus']['route']);
        self::assertSame('done', $result['computationStatus']['stages']);
        self::assertSame('pending', $result['computationStatus']['terrain']);
    }

    #[Test]
    public function anUploadBelowTheMinimumStaysDraftAndSaysWhy(): void
    {
        $service = $this->service([$this->stage(1)]);

        $result = $service->createTrip([new Coordinate(45.0, 5.0, 100.0), new Coordinate(45.1, 5.1, 200.0)], null, new TripRequest(), 'en', new User('owner@test.com'));

        self::assertSame([
            'initializeTrip:en',
            'initializeComputations:'.\count(ComputationName::pipeline()),
            'storeRawPoints:2',
            'storeSourceType:gpx_upload',
            'storeTitle:',
            'storeDecimatedPoints:2',
            'publish:route_parsed',
            'publishValidationError:MIN_STAGES',
            'storeStages:1',
            'publish:stages_computed',
            'publishComputationStepCompleted:stages:3/14/1',
        ], array_values(array_filter($this->log, static fn (string $entry): bool => !str_starts_with($entry, 'dispatch:'))));
        self::assertSame('draft', $result['status']);
    }

    /** @var list<string> */
    private array $tripIdsSeen = [];

    /** @var list<int|null> */
    private array $dispatchedGenerations = [];

    /**
     * @return list<string>
     */
    private function tripIds(): array
    {
        return array_values(array_unique($this->tripIdsSeen));
    }

    /**
     * @param list<Stage> $stages
     */
    private function service(array $stages): GpxUploadService
    {
        $log = &$this->log;
        $events = &$this->events;
        $tripIds = &$this->tripIdsSeen;

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('initializeTrip')->willReturnCallback(static function (string $tripId, TripRequest $request, ?string $locale) use (&$log, &$tripIds): void {
            $tripIds[] = $tripId;
            $log[] = 'initializeTrip:'.$locale;
        });
        $repository->method('storeSourceType')->willReturnCallback(static function (string $tripId, string $type) use (&$log): void {
            $log[] = 'storeSourceType:'.$type;
        });
        $repository->method('storeTitle')->willReturnCallback(static function (string $tripId, ?string $title) use (&$log): void {
            $log[] = 'storeTitle:'.$title;
        });
        $repository->method('storeStatus')->willReturnCallback(static function (string $tripId, string $status) use (&$log): void {
            $log[] = 'storeStatus:'.$status;
        });
        $repository->method('getRequest')->willReturn(new TripRequest());

        $points = $this->createStub(TransientTripPointsStoreInterface::class);
        $points->method('storeRawPoints')->willReturnCallback(static function (string $tripId, array $raw) use (&$log): void {
            $log[] = 'storeRawPoints:'.\count($raw);
        });
        $points->method('storeDecimatedPoints')->willReturnCallback(static function (string $tripId, array $decimated) use (&$log): void {
            $log[] = 'storeDecimatedPoints:'.\count($decimated);
        });
        $points->method('getDecimatedPoints')->willReturn([['lat' => 45.0, 'lon' => 5.0, 'ele' => 100.0], ['lat' => 45.1, 'lon' => 5.1, 'ele' => 200.0]]);

        $stageStore = $this->createStub(TripStageStoreInterface::class);
        $stageStore->method('storeStages')->willReturnCallback(static function (string $tripId, array $stored) use (&$log): int {
            $log[] = 'storeStages:'.\count($stored);

            return 2;
        });

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('initializeComputations')->willReturnCallback(static function (string $tripId, array $computations) use (&$log): void {
            $log[] = 'initializeComputations:'.\count($computations);
        });
        $tracker->method('getProgress')->willReturn(['completed' => 3, 'failed' => 1, 'settled' => 4, 'total' => 14]);

        $generations = $this->createStub(TripGenerationTrackerInterface::class);
        $generations->method('current')->willReturn(1);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(static function (string $tripId, MercureEventType $type, array $data = []) use (&$log, &$events, &$tripIds): void {
            $tripIds[] = $tripId;
            $log[] = 'publish:'.$type->value;
            $events[] = [$type->value, $data];
        });
        $publisher->method('publishValidationError')->willReturnCallback(static function (string $tripId, string $code) use (&$log): void {
            $log[] = 'publishValidationError:'.$code;
        });
        $publisher->method('publishComputationStepCompleted')->willReturnCallback(static function (string $tripId, ComputationName $step, int $completed, int $total, int $failed = 0) use (&$log): void {
            $log[] = \sprintf('publishComputationStepCompleted:%s:%d/%d/%d', $step->value, $completed, $total, $failed);
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $dispatchedGenerations = &$this->dispatchedGenerations;
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$log, &$dispatchedGenerations): Envelope {
            $log[] = 'dispatch:'.$message::class;
            if ($message instanceof BelongsToATripGeneration) {
                $dispatchedGenerations[] = $message->generation;
            }

            return new Envelope($message);
        });

        $distance = $this->createStub(DistanceCalculatorInterface::class);
        $distance->method('calculateTotalDistance')->willReturn(123.456);
        $elevation = $this->createStub(ElevationCalculatorInterface::class);
        $elevation->method('calculateTotalAscent')->willReturn(800.7);
        $elevation->method('calculateTotalDescent')->willReturn(700.2);
        $simplifier = $this->createStub(RouteSimplifierInterface::class);
        $simplifier->method('simplify')->willReturnArgument(0);

        $pacing = $this->createStub(PacingEngineInterface::class);
        $pacing->method('generateStages')->willReturn($stages);

        $structural = new StructuralComputationService($repository, $points, $distance, $elevation, $simplifier, $pacing);

        return new GpxUploadService(
            $this->createStub(GpxRouteParserInterface::class),
            new TripBootstrapper($repository, $tracker, $generations, $points, $simplifier, $distance, $elevation, $publisher, $stageStore, $structural),
            $repository,
            $tracker,
            new ProgressPublisher($tracker, $publisher),
            new TripLocker(new NativeClock()),
            new TripAnalysisDispatcher($bus, new EnrichmentMessageFactory()),
        );
    }

    private function stage(int $day): Stage
    {
        return new Stage(
            tripId: 'trip',
            dayNumber: $day,
            distance: 60.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.0, 5.0, 100.0),
            endPoint: new Coordinate(45.1, 5.1, 200.0),
        );
    }
}
