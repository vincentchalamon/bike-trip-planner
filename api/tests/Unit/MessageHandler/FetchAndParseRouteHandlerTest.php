<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Tests\Unit\AlertMessageTestTrait;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Mercure\MercureEventType;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\MessageHandler\FetchAndParseRouteHandler;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\RouteFetcher\RouteFetcherInterface;
use App\RouteFetcher\RouteFetcherRegistryInterface;
use App\RouteFetcher\RouteFetchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FetchAndParseRouteHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    #[Test]
    public function aFailedFetchPublishesAClearValidationErrorWithoutRetrying(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $points = $this->createStub(TransientTripPointsStoreInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);

        // The fetcher fails with the documented RuntimeException (e.g. a private tour).
        $fetcher = $this->createStub(RouteFetcherInterface::class);
        $fetcher->method('fetch')->willThrowException(new \RuntimeException('Komoot tour 123 is private or access denied (403).'));
        $registry = $this->createStub(RouteFetcherRegistryInterface::class);
        $registry->method('get')->willReturn($fetcher);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 1]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        // The raw exception detail stays in the logs; the user gets a stable,
        // friendly message (no leaked cURL/transport internals).
        $publisher->expects($this->once())
            ->method('publishValidationError')
            ->with('trip-1', 'ROUTE_FETCH_FAILED', 'The route could not be fetched. Please check the URL and try again.');

        // A terminal fetch failure must not re-dispatch: no GenerateStages, no retry.
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $handler = new FetchAndParseRouteHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $this->createStub(TripStageStoreInterface::class),
            $points,
            $registry,
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $messageBus,
            $this->createAlertRenderer(),
        );

        // The handler must return normally (computation marked done), not re-throw.
        $handler(new FetchAndParseRoute('trip-1'));
    }

    /**
     * Pins what a fetched route writes and publishes, in order, for a single tour and for a
     * collection: `route_parsed` then the progress step, the raw tracks only for a collection,
     * and the stage generation handed to the next worker.
     *
     * @return iterable<string, array{list<list<Coordinate>>, list<string>}>
     */
    public static function fetchedRoutes(): iterable
    {
        $a = new Coordinate(45.0, 5.0, 100.0);
        $b = new Coordinate(45.1, 5.1, 200.0);

        yield 'tour' => [[[$a, $b]], [
            'storeRawPoints:2',
            'storeSourceType:komoot_tour',
            'storeTitle:My Tour',
            'storeDecimatedPoints:2',
            'publish:route_parsed',
            'dispatch:'.GenerateStages::class.':4',
            'publishComputationStepCompleted:route:1/14/0',
        ]];

        yield 'collection' => [[[$a, $b], [$b, $a, $b]], [
            'storeRawPoints:5',
            'storeSourceType:komoot_tour',
            'storeTitle:My Tour',
            'storeDecimatedPoints:5',
            'publish:route_parsed',
            'storeTracksData:2',
            'dispatch:'.GenerateStages::class.':4',
            'publishComputationStepCompleted:route:1/14/0',
        ]];
    }

    /**
     * @param list<list<Coordinate>> $tracks
     * @param list<string>           $expected
     */
    #[Test]
    #[DataProvider('fetchedRoutes')]
    public function aFetchedRouteWritesAndPublishesInAFixedOrder(array $tracks, array $expected): void
    {
        $log = [];
        $payloads = [];

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getRequest')->willReturn($request);
        $repository->method('storeSourceType')->willReturnCallback(static function (string $tripId, string $type) use (&$log): void {
            $log[] = 'storeSourceType:'.$type;
        });
        $repository->method('storeTitle')->willReturnCallback(static function (string $tripId, ?string $title) use (&$log): void {
            $log[] = 'storeTitle:'.$title;
        });

        $points = $this->createStub(TransientTripPointsStoreInterface::class);
        $points->method('storeRawPoints')->willReturnCallback(static function (string $tripId, array $raw) use (&$log): void {
            $log[] = 'storeRawPoints:'.\count($raw);
        });
        $points->method('storeDecimatedPoints')->willReturnCallback(static function (string $tripId, array $decimated) use (&$log): void {
            $log[] = 'storeDecimatedPoints:'.\count($decimated);
        });
        $points->method('storeTracksData')->willReturnCallback(static function (string $tripId, array $data) use (&$log): void {
            $log[] = 'storeTracksData:'.\count($data);
        });

        $fetcher = $this->createStub(RouteFetcherInterface::class);
        $fetcher->method('fetch')->willReturn(new RouteFetchResult(SourceType::KOMOOT_TOUR, $tracks, 'My Tour'));
        $registry = $this->createStub(RouteFetcherRegistryInterface::class);
        $registry->method('get')->willReturn($fetcher);

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getProgress')->willReturn(['completed' => 1, 'failed' => 0, 'settled' => 1, 'total' => 14]);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(static function (string $tripId, MercureEventType $type, array $data = []) use (&$log, &$payloads): void {
            $log[] = 'publish:'.$type->value;
            $payloads[] = $data;
        });
        $publisher->method('publishComputationStepCompleted')->willReturnCallback(static function (string $tripId, ComputationName $step, int $completed, int $total, int $failed = 0) use (&$log): void {
            $log[] = \sprintf('publishComputationStepCompleted:%s:%d/%d/%d', $step->value, $completed, $total, $failed);
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$log): Envelope {
            \assert($message instanceof GenerateStages);
            $log[] = 'dispatch:'.$message::class.':'.$message->generation;

            return new Envelope($message);
        });

        $distance = $this->createStub(DistanceCalculatorInterface::class);
        $distance->method('calculateTotalDistance')->willReturn(123.456);
        $elevation = $this->createStub(ElevationCalculatorInterface::class);
        $elevation->method('calculateTotalAscent')->willReturn(800.7);
        $elevation->method('calculateTotalDescent')->willReturn(700.2);
        $simplifier = $this->createStub(RouteSimplifierInterface::class);
        $simplifier->method('simplify')->willReturnArgument(0);

        $handler = new FetchAndParseRouteHandler(
            $tracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $repository,
            $this->createStub(TripStageStoreInterface::class),
            $points,
            $registry,
            $distance,
            $elevation,
            $simplifier,
            $bus,
            $this->createAlertRenderer(),
        );

        $handler(new FetchAndParseRoute('trip-1', 4));

        self::assertSame($expected, $log);
        self::assertSame([[
            'totalDistance' => 123.5,
            'totalElevation' => 800,
            'totalElevationLoss' => 700,
            'sourceType' => 'komoot_tour',
            'title' => 'My Tour',
        ]], $payloads);
    }
}
