<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Message\FetchAndParseRoute;
use App\MessageHandler\FetchAndParseRouteHandler;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\RouteFetcher\RouteFetcherInterface;
use App\RouteFetcher\RouteFetcherRegistryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class FetchAndParseRouteHandlerTest extends TestCase
{
    #[Test]
    public function aFailedFetchPublishesAClearValidationErrorWithoutRetrying(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($request);

        // The fetcher fails with the documented RuntimeException (e.g. a private tour).
        $fetcher = $this->createStub(RouteFetcherInterface::class);
        $fetcher->method('fetch')->willThrowException(new \RuntimeException('Komoot tour 123 is private or access denied (403).'));
        $registry = $this->createStub(RouteFetcherRegistryInterface::class);
        $registry->method('get')->willReturn($fetcher);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

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
            $registry,
            $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $messageBus,
        );

        // The handler must return normally (computation marked done), not re-throw.
        $handler(new FetchAndParseRoute('trip-1'));
    }
}
