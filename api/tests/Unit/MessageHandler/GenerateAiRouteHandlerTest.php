<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\SourceType;
use App\Generation\AiGeneratedRoute;
use App\Generation\AiTripGenerationServiceInterface;
use App\Llm\AiProvider;
use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\TripLlmResolverInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateAiRoute;
use App\Message\GenerateStages;
use App\MessageHandler\GenerateAiRouteHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class GenerateAiRouteHandlerTest extends TestCase
{
    private const string TRIP_ID = 'trip-ai-1';

    #[Test]
    public function successStoresGeometrySetsAiSourceAndDispatchesGenerateStages(): void
    {
        $route = AiGeneratedRoute::success(
            ['start' => 'Lille', 'loop' => true, 'km_per_day' => 80],
            [new Coordinate(50.6, 3.0), new Coordinate(50.2, 3.2)],
            150.0,
        );

        $service = $this->createMock(AiTripGenerationServiceInterface::class);
        $service->method('generate')->willReturn($route);

        $capturedRequest = null;
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->method('getRequest')->willReturn(new TripRequest());
        $repository->expects(self::once())->method('storeRawPoints')
            ->with(self::TRIP_ID, self::callback(static fn (array $points): bool => 2 === \count($points)));
        $repository->expects(self::once())->method('storeDecimatedPoints');
        $repository->expects(self::once())->method('storeSourceType')
            ->with(self::TRIP_ID, SourceType::AI_GENERATED->value);
        $repository->expects(self::once())->method('storeTitle')
            ->with(self::TRIP_ID, self::callback(static fn (?string $t): bool => \is_string($t) && str_contains($t, 'Lille')));
        $repository->expects(self::once())->method('storeRequest')
            ->willReturnCallback(static function (string $id, TripRequest $r) use (&$capturedRequest): void {
                $capturedRequest = $r;
            });

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::never())->method('publishValidationError');
        $publisher->expects(self::once())->method('publish')
            ->with(self::TRIP_ID, MercureEventType::ROUTE_PARSED, self::anything());

        $bus = $this->newMessageBus();
        $handler = $this->handler($service, $this->resolved(), $repository, $publisher, $bus);

        $handler(new GenerateAiRoute(self::TRIP_ID, 'boucle Lille 80 km/j', 'fr', 1));

        $dispatched = $this->collectDispatched($bus, GenerateStages::class);
        self::assertCount(1, $dispatched);
        self::assertSame(self::TRIP_ID, $dispatched[0]->tripId);
        self::assertSame(1, $dispatched[0]->generation);

        // The model's km/day drives the pacing day count.
        self::assertInstanceOf(TripRequest::class, $capturedRequest);
        self::assertEqualsWithDelta(80.0, $capturedRequest->maxDistancePerDay, 0.01);
    }

    /**
     * @return iterable<string, array{AiGeneratedRoute, string}>
     */
    public static function nonSuccessOutcomes(): iterable
    {
        yield 'out of zone' => [AiGeneratedRoute::outOfZone('', 'fr'), 'OUT_OF_ZONE'];
        yield 'unparseable' => [AiGeneratedRoute::unparseable('fr'), 'UNPARSEABLE'];
        yield 'ungeocodable' => [AiGeneratedRoute::ungeocodable([], 'Impossible de localiser Atlantis.'), 'UNGEOCODABLE'];
        yield 'routing failed' => [AiGeneratedRoute::routingFailed([], 'fr'), 'ROUTING_FAILED'];
    }

    #[Test]
    #[DataProvider('nonSuccessOutcomes')]
    public function nonSuccessOutcomePublishesValidationErrorAndStops(AiGeneratedRoute $route, string $expectedCode): void
    {
        $service = $this->createMock(AiTripGenerationServiceInterface::class);
        $service->method('generate')->willReturn($route);

        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->expects(self::never())->method('storeRawPoints');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::once())->method('publishValidationError')
            ->with(self::TRIP_ID, $expectedCode, self::callback(static fn (string $m): bool => '' !== $m));

        $bus = $this->newMessageBus();
        $handler = $this->handler($service, $this->resolved(), $repository, $publisher, $bus);

        $handler(new GenerateAiRoute(self::TRIP_ID, 'brief', 'fr', 1));

        self::assertCount(0, $this->collectDispatched($bus, GenerateStages::class));
    }

    #[Test]
    public function transientProviderErrorIsRethrownForRetry(): void
    {
        $service = $this->createMock(AiTripGenerationServiceInterface::class);
        $service->method('generate')->willThrowException(
            new AiUnavailableException('upstream 503', AiFailureReason::UNAVAILABLE),
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        // A transient failure is not a user-facing validation error — it is retried.
        $publisher->expects(self::never())->method('publishValidationError');

        $bus = $this->newMessageBus();
        $handler = $this->handler($service, $this->resolved(), $this->createStub(TripRequestRepositoryInterface::class), $publisher, $bus);

        try {
            $handler(new GenerateAiRoute(self::TRIP_ID, 'brief', 'fr', 1));
            self::fail('Expected AiUnavailableException to propagate for Messenger retry.');
        } catch (AiUnavailableException) {
            // Expected: executeWithTracking re-throws so the message is retried.
        }

        self::assertCount(0, $this->collectDispatched($bus, GenerateStages::class));
    }

    #[Test]
    public function terminalProviderErrorFailsFastWithoutRetry(): void
    {
        $service = $this->createMock(AiTripGenerationServiceInterface::class);
        $service->method('generate')->willThrowException(
            new AiUnavailableException('401 unauthorized', AiFailureReason::INVALID_TOKEN),
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::once())->method('publishValidationError')
            ->with(self::TRIP_ID, 'AI_UNAVAILABLE', self::callback(static fn (string $m): bool => '' !== $m));

        $bus = $this->newMessageBus();
        $handler = $this->handler($service, $this->resolved(), $this->createStub(TripRequestRepositoryInterface::class), $publisher, $bus);

        // Must return normally (no re-throw) so Messenger does not retry on a terminal error.
        $handler(new GenerateAiRoute(self::TRIP_ID, 'brief', 'fr', 1));

        self::assertCount(0, $this->collectDispatched($bus, GenerateStages::class));
    }

    #[Test]
    public function publishesValidationErrorWhenOwnerHasNoProvider(): void
    {
        $service = $this->createMock(AiTripGenerationServiceInterface::class);
        $service->expects(self::never())->method('generate');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::once())->method('publishValidationError')
            ->with(self::TRIP_ID, 'AI_NOT_CONFIGURED', self::callback(static fn (string $m): bool => '' !== $m));

        $bus = $this->newMessageBus();
        $handler = $this->handler($service, null, $this->createStub(TripRequestRepositoryInterface::class), $publisher, $bus);

        $handler(new GenerateAiRoute(self::TRIP_ID, 'brief', 'fr', 1));

        self::assertCount(0, $this->collectDispatched($bus, GenerateStages::class));
    }

    private function handler(
        AiTripGenerationServiceInterface $service,
        ?ResolvedLlmClient $resolved,
        TripRequestRepositoryInterface $repository,
        TripUpdatePublisherInterface $publisher,
        MessageBusInterface $messageBus,
    ): GenerateAiRouteHandler {
        $tripLlmResolver = $this->createStub(TripLlmResolverInterface::class);
        $tripLlmResolver->method('resolveForTrip')->willReturn($resolved);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        // Total > settled so the AllEnrichmentsCompleted gate never fires here.
        $computationTracker->method('getProgress')->willReturn(['completed' => 1, 'failed' => 0, 'total' => 18]);

        $routeSimplifier = $this->createStub(RouteSimplifierInterface::class);
        $routeSimplifier->method('simplify')->willReturnArgument(0);

        return new GenerateAiRouteHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $repository,
            $tripLlmResolver,
            $service,
            $routeSimplifier,
            $messageBus,
        );
    }

    private function resolved(): ResolvedLlmClient
    {
        return new ResolvedLlmClient($this->createStub(LlmClientInterface::class), AiProvider::ANTHROPIC);
    }

    private function newMessageBus(): MessageBusInterface
    {
        return new class () implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = $message instanceof Envelope ? $message : new Envelope($message, $stamps);
                $this->dispatched[] = $envelope;

                return $envelope;
            }
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $messageClass
     *
     * @return list<T>
     */
    private function collectDispatched(MessageBusInterface $bus, string $messageClass): array
    {
        \assert(property_exists($bus, 'dispatched'));

        $collected = [];
        /** @var Envelope $envelope */
        foreach ($bus->dispatched as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof $messageClass) {
                $collected[] = $message;
            }
        }

        return $collected;
    }
}
