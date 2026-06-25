<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\ApiResource\Model\GeoPosition;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\TripChatContext;
use App\ApiResource\Stage;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripChatResponse;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\TripChatMessage;
use App\Entity\User;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRideAssistant;
use App\InRide\InRidePoiRepositoryInterface;
use App\InRide\OpeningHoursParser;
use App\InRide\PoiIntentDetector;
use App\Llm\AiProvider;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponseParser;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use App\Llm\UserLlmResolverInterface;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripChatProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies that the chat processor maps each parsed action to the right
 * inline recomputation dispatch (issue #311):
 *  - `split_stage` → RecalculateStages on 2 halves
 *  - `merge_stages` → RecalculateStages on the merged stage
 *  - `add_waypoint` → RecalculateStages on 1 stage
 *  - `change_accommodation` → RecalculateStages on arrival + next stage
 *  - `adjust_distance` → RecalculateStages on 1 stage
 *  - `info` → no dispatch
 *  - `change_route` → no dispatch, requiresFullAnalysis=true.
 *
 * Also covers the 429 rate-limit branch — exercised here because the test
 * cache pool resets between kernel requests at the functional layer so
 * a multi-request HTTP test cannot accumulate limiter state.
 *
 * All recomputation dispatches must carry `skipAiAnalysis: true` so the
 * LLaMA 8B re-analysis is skipped by default during inline edits.
 */
#[AllowMockObjectsWithoutExpectations]
final class TripChatProcessorTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000099';

    private string $promptFixtureDir = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ('' !== $this->promptFixtureDir && is_dir($this->promptFixtureDir)) {
            @unlink($this->promptFixtureDir.\DIRECTORY_SEPARATOR.'dialogue.txt');
            @rmdir($this->promptFixtureDir);
        }
    }

    #[Test]
    public function splitStageActionDispatchesRecomputeForBothHalves(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('split_stage', ['stage' => 3], 'OK'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("Coupe l'étape 3 en deux", new TripChatContext(currentStage: 3)),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('split_stage', $response->action);
        self::assertTrue($response->dispatched);
        self::assertSame([3, 4], $response->impactedStageNumbers);
        self::assertFalse($response->requiresFullAnalysis);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([2, 3], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    /**
     * Splitting the last stage of the trip yields a brand-new day number beyond
     * the current stage list. Both halves are still surfaced in
     * {@see TripChatResponse::$impactedStageNumbers} so the frontend can shimmer
     * the new slot once the recomputation lands.
     */
    #[Test]
    public function splitLastStageActionDispatchesBothHalves(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('split_stage', ['stage' => 5], 'OK'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Coupe la dernière étape en deux'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame([5, 6], $response->impactedStageNumbers);
        self::assertTrue($response->dispatched);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([4, 5], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    #[Test]
    public function mergeStagesActionDispatchesRecomputeForMergedStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('merge_stages', ['stages' => [2, 3]], 'OK'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Fusionne les étapes 2 et 3'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertTrue($response->dispatched);
        self::assertSame([2], $response->impactedStageNumbers);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([1], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    /**
     * The system prompt does not constrain the order of the merge pair, so the
     * LLM may emit `{"stages": [3, 2]}` for the same intent. The processor must
     * normalise the surviving stage to the lower index so the right card
     * shimmers in the UI.
     */
    #[Test]
    public function mergeStagesReversedOrderDispatchesLowerStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('merge_stages', ['stages' => [3, 2]], 'OK'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Fusionne les étapes 2 et 3'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame([2], $response->impactedStageNumbers);
        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([1], $messages[0]->affectedIndices);
    }

    #[Test]
    public function addWaypointActionDispatchesRecomputeForTheStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'add_waypoint',
                ['name' => 'Mont Cassel', 'stage' => 4],
                'Ajout',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Ajoute un détour par le Mont Cassel'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertTrue($response->dispatched);
        self::assertSame([4], $response->impactedStageNumbers);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([3], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    /**
     * The dialogue prompt allows `add_waypoint` with `stage: null` when the rider
     * does not specify a day. In that case the processor returns a successful
     * action but does not dispatch any recomputation — the frontend reads
     * `dispatched=false` and surfaces the conversational reply only.
     *
     * Behaviour is documented here so callers don't mistake the no-op for a bug.
     */
    #[Test]
    public function addWaypointWithNullStageIsAcceptedButDispatchesNothing(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'add_waypoint',
                ['name' => 'Mont Cassel', 'stage' => null],
                'Ajout sans étape précise',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("Ajoute un point d'eau quelque part"),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('add_waypoint', $response->action);
        self::assertFalse($response->dispatched);
        self::assertSame([], $response->impactedStageNumbers);
        self::assertEmpty($this->collectDispatched($bus, RecalculateStages::class));
    }

    #[Test]
    public function changeAccommodationActionRecomputesArrivalAndNextStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'change_accommodation',
                ['stage' => 2, 'type' => 'guest_house'],
                'OK',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Sur cette étape je préfère dormir en gîte'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertTrue($response->dispatched);
        self::assertSame([2, 3], $response->impactedStageNumbers);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([1, 2], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    #[Test]
    public function changeAccommodationOnLastStageOnlyRecomputesTheStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'change_accommodation',
                ['stage' => 5, 'type' => 'camp_site'],
                'OK',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Camping pour la dernière étape'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame([5], $response->impactedStageNumbers);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([4], $messages[0]->affectedIndices);
    }

    #[Test]
    public function adjustDistanceActionDispatchesRecomputeForOneStage(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'adjust_distance',
                ['stage' => 5, 'km' => 95],
                'OK',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("Allonge l'étape 5 à 95 km"),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertTrue($response->dispatched);
        self::assertSame([5], $response->impactedStageNumbers);

        $messages = $this->collectDispatched($bus, RecalculateStages::class);
        self::assertCount(1, $messages);
        self::assertSame([4], $messages[0]->affectedIndices);
        self::assertTrue($messages[0]->skipAiAnalysis);
    }

    #[Test]
    public function infoActionDispatchesNothing(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('info', [], 'Le gravel désigne…'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("C'est quoi le gravel ?"),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('info', $response->action);
        self::assertFalse($response->dispatched);
        self::assertSame([], $response->impactedStageNumbers);
        self::assertFalse($response->requiresFullAnalysis);

        self::assertCount(0, $this->collectDispatched($bus, RecalculateStages::class));
    }

    #[Test]
    public function changeRouteActionFlagsFullAnalysisAndDispatchesNothing(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope(
                'change_route',
                [],
                'Cette modification touche tout le tracé.',
            ),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("Change l'itinéraire pour passer par la côte"),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('change_route', $response->action);
        self::assertTrue($response->requiresFullAnalysis);
        self::assertFalse($response->dispatched);
        self::assertSame([], $response->impactedStageNumbers);

        self::assertCount(0, $this->collectDispatched($bus, RecalculateStages::class));
    }

    #[Test]
    public function dispatchesNothingWhenStageNumberIsOutOfRange(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('split_stage', ['stage' => 99], 'OK'),
            stagesCount: 5,
            messageBus: $bus,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest("Coupe l'étape 99"),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertFalse($response->dispatched);
        self::assertSame([], $response->impactedStageNumbers);
        self::assertCount(0, $this->collectDispatched($bus, RecalculateStages::class));
    }

    #[Test]
    public function processThrows429WhenRateLimitExceeded(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'trip_chat_test_429', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('info', [], 'OK'),
            stagesCount: 1,
            messageBus: $this->newMessageBus(),
            limiterFactory: $factory,
        );

        // First call consumes the only token.
        $processor->process(new TripChatRequest('Bonjour'), new Post(), ['id' => self::TRIP_ID]);

        // Second call must hit the limiter and trip the 429 branch.
        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process(new TripChatRequest('Encore'), new Post(), ['id' => self::TRIP_ID]);
    }

    /**
     * Long-term persistence regression test (#458): every chat turn must be
     * written to PostgreSQL *in addition* to the rolling Redis context window
     * so the rider can recover their conversation from a cold reload.
     */
    #[Test]
    public function chatTurnIsPersistedToPostgresInAdditionToRedis(): void
    {
        $persisted = [];
        $flushed = 0;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getReference')
            ->willReturnCallback(static fn (string $class, string $id): TripRequest => new TripRequest(Uuid::fromString($id)));
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use (&$flushed): void {
                ++$flushed;
            });

        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('info', [], 'Réponse persistée'),
            stagesCount: 3,
            messageBus: $this->newMessageBus(),
            entityManager: $entityManager,
        );

        $processor->process(
            new TripChatRequest('Quel est mon dénivelé total ?'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertCount(2, $persisted, 'Both the user turn and the assistant reply must be persisted.');
        self::assertSame(1, $flushed, 'A single flush is expected per chat turn.');

        /** @var TripChatMessage $userTurn */
        $userTurn = $persisted[0];
        /** @var TripChatMessage $assistantTurn */
        $assistantTurn = $persisted[1];

        self::assertInstanceOf(TripChatMessage::class, $userTurn);
        self::assertInstanceOf(TripChatMessage::class, $assistantTurn);
        self::assertSame(TripChatMessage::ROLE_USER, $userTurn->getRole());
        self::assertSame(TripChatMessage::ROLE_ASSISTANT, $assistantTurn->getRole());
        self::assertStringContainsString('Quel est mon dénivelé total ?', $userTurn->getContent());
        self::assertSame('info', $assistantTurn->getAction());
        // Planning turns carry no geo / POI data — those columns must stay null.
        self::assertNull($userTurn->getGeoLat());
        self::assertNull($assistantTurn->getGeoLat());
        self::assertNull($assistantTurn->getPois());
    }

    /**
     * Counterpart to the previous test for the in-ride branch: a chat request
     * carrying a GPS position must persist the geo columns and the POI list on
     * the assistant turn so {@see \App\State\TripChatHistoryProvider} can
     * rehydrate the PoiCard renderings after a page reload.
     */
    #[Test]
    public function inRideTurnIsPersistedWithGeoAndPois(): void
    {
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getReference')
            ->willReturnCallback(static fn (string $class, string $id): TripRequest => new TripRequest(Uuid::fromString($id)));
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->method('flush');

        // The index returns one named water POI close to the rider so the
        // in-ride pipeline produces a non-empty $poisPayload that
        // `persistChatTurn` stores on the assistant turn.
        $inRidePois = [
            ['lat' => 48.8570, 'lon' => 2.3530, 'tags' => ['name' => 'Fontaine Wallace']],
        ];

        $processor = $this->newProcessor(
            // The first LLM call (intent detection) returns a water envelope;
            // the narrative call uses the same chat() stub which we also wire
            // for the planning fallback. Both paths come through `chat()` in
            // PoiIntentDetector → LlmClientInterface::generate, so we drive
            // them via the chat stub on InRideAssistant's narrative call.
            llmContent: '{"category":"water","max_distance_m":3000}',
            stagesCount: 3,
            messageBus: $this->newMessageBus(),
            entityManager: $entityManager,
            inRidePois: $inRidePois,
        );

        $processor->process(
            new TripChatRequest(
                message: "Un point d'eau pas loin ?",
                position: new GeoPosition(lat: 48.8566, lon: 2.3522),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertCount(2, $persisted, 'Both user and assistant turns must be persisted for in-ride messages.');

        /** @var TripChatMessage $userTurn */
        $userTurn = $persisted[0];
        /** @var TripChatMessage $assistantTurn */
        $assistantTurn = $persisted[1];

        self::assertSame(TripChatMessage::ROLE_USER, $userTurn->getRole());
        self::assertSame(TripChatMessage::ROLE_ASSISTANT, $assistantTurn->getRole());

        // Geo columns must be populated so ChatHistoryLoader can show the
        // rider's position context after a page reload.
        self::assertEqualsWithDelta(48.8566, $userTurn->getGeoLat(), 0.0001);
        self::assertEqualsWithDelta(2.3522, $userTurn->getGeoLon(), 0.0001);
        self::assertEqualsWithDelta(48.8566, $assistantTurn->getGeoLat(), 0.0001);
        self::assertEqualsWithDelta(2.3522, $assistantTurn->getGeoLon(), 0.0001);

        // POI payload is attached to the assistant turn only; the user prompt has none.
        self::assertNull($userTurn->getPois());

        // The scanner returned one POI → the assistant turn must carry the
        // serialised payload so a page-reload rehydration can paint PoiCards.
        $pois = $assistantTurn->getPois();
        self::assertIsArray($pois);
        self::assertCount(1, $pois);
        self::assertSame('Fontaine Wallace', $pois[0]['name']);
    }

    /**
     * Without the optional repository/entity manager (e.g. legacy wiring during
     * the rollout), the processor must continue to function: the Redis history
     * is the legacy single source of truth and a missing PG writer should not
     * break the chat endpoint.
     */
    #[Test]
    public function chatStillWorksWhenChatMessageRepositoryIsUnwired(): void
    {
        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('info', [], 'OK'),
            stagesCount: 3,
            messageBus: $this->newMessageBus(),
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('info', $response->action);
    }

    /**
     * When PostgreSQL is reachable but the persist or flush throws (FK
     * violation, connection drop mid-transaction, …), the chat endpoint must
     * still return a usable response — the Redis sliding-window context has
     * already been updated and remains authoritative for the next LLM call.
     */
    #[Test]
    public function chatStillReturnsResponseWhenPgPersistFails(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getReference')
            ->willReturnCallback(static fn (string $class, string $id): TripRequest => new TripRequest(Uuid::fromString($id)));
        $entityManager->method('wrapInTransaction')
            ->willThrowException(new \RuntimeException('PG connection lost'));

        // Capture the swallowed exception via the logger contract so a future
        // refactor that silently drops the `logger->warning(...)` call cannot
        // hide the failure from operators.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('Failed to persist trip chat history'),
                self::callback(static fn (array $ctx): bool => 'PG connection lost' === ($ctx['error'] ?? null)),
            );

        $processor = $this->newProcessor(
            llmContent: $this->jsonEnvelope('info', [], 'OK même si PG est cassé'),
            stagesCount: 3,
            messageBus: $this->newMessageBus(),
            entityManager: $entityManager,
            logger: $logger,
        );

        $response = $this->processTurn(
            $processor,
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame('info', $response->action);
        self::assertSame('OK même si PG est cassé', $response->response);
    }

    #[Test]
    public function returns503AndLogsCriticalWhenProviderUnreachable(): void
    {
        // AI configured but the chat call hits an unreachable provider: 503 + `critical` log (#304).
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')
            ->with('critical', self::stringContains('AI provider call failed'), self::anything());

        $processor = $this->newProcessor(
            llmContent: '',
            stagesCount: 1,
            messageBus: $this->newMessageBus(),
            logger: $logger,
            chatException: new AiUnavailableException('boom'),
        );

        $result = $processor->process(
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(503, $result->getStatusCode());
        self::assertSame('{"error":"ai_unavailable"}', $result->getContent());
    }

    #[Test]
    public function returns422AndLogsWarningForInvalidToken(): void
    {
        // A bad key is a user-config error: warning, not critical (no on-call page),
        // and an actionable 422 the UI surfaces with a settings CTA (#761) rather
        // than a misleading "retry" 503.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')
            ->with('warning', self::stringContains('AI provider call failed'), self::anything());

        $processor = $this->newProcessor(
            llmContent: '',
            stagesCount: 1,
            messageBus: $this->newMessageBus(),
            logger: $logger,
            chatException: new AiUnavailableException('bad key', AiFailureReason::INVALID_TOKEN),
        );

        $result = $processor->process(
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(422, $result->getStatusCode());
        self::assertSame('{"error":"ai_invalid_token"}', $result->getContent());
    }

    #[Test]
    public function returns422ForExhaustedQuota(): void
    {
        $processor = $this->newProcessor(
            llmContent: '',
            stagesCount: 1,
            messageBus: $this->newMessageBus(),
            chatException: new AiUnavailableException('no credit', AiFailureReason::QUOTA_EXCEEDED),
        );

        $result = $processor->process(
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(422, $result->getStatusCode());
        self::assertSame('{"error":"ai_quota_exceeded"}', $result->getContent());
    }

    #[Test]
    public function returns429WithRetryAfterWhenRateLimitedByProvider(): void
    {
        $processor = $this->newProcessor(
            llmContent: '',
            stagesCount: 1,
            messageBus: $this->newMessageBus(),
            chatException: new AiUnavailableException('slow down', AiFailureReason::RATE_LIMITED, retryAfter: 12),
        );

        $result = $processor->process(
            new TripChatRequest('Bonjour'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(429, $result->getStatusCode());
        self::assertSame('{"error":"ai_rate_limited"}', $result->getContent());
        self::assertSame('12', $result->headers->get('Retry-After'));
    }

    /**
     * Runs a planning-mode chat turn and narrows the union return type to the
     * success DTO — a provider failure would return a JsonResponse instead
     * (#761). Mirrors the assertInstanceOf guard in TripAiChatProcessorTest.
     *
     * @param array<string, string> $uriVariables
     */
    private function processTurn(
        TripChatProcessor $processor,
        TripChatRequest $request,
        Post $operation,
        array $uriVariables,
    ): TripChatResponse {
        $result = $processor->process($request, $operation, $uriVariables);
        self::assertInstanceOf(TripChatResponse::class, $result);

        return $result;
    }

    /**
     * @param list<array{lat: float, lon: float, tags: array<string, string>}>|null $inRidePois optional features returned by the in-ride index mock
     */
    private function newProcessor(
        string $llmContent,
        int $stagesCount,
        MessageBusInterface $messageBus,
        ?RateLimiterFactory $limiterFactory = null,
        ?EntityManagerInterface $entityManager = null,
        ?array $inRidePois = null,
        ?LoggerInterface $logger = null,
        ?\Throwable $chatException = null,
    ): TripChatProcessor {
        $tripRequest = new TripRequest();
        $stages = [];
        for ($i = 1; $i <= $stagesCount; ++$i) {
            $stages[] = new Stage(
                tripId: self::TRIP_ID,
                dayNumber: $i,
                distance: 50.0,
                elevation: 200.0,
                startPoint: new Coordinate(48.0, 2.0, 0.0),
                endPoint: new Coordinate(48.1, 2.1, 0.0),
            );
        }

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getRequest')->willReturn($tripRequest);
        $repository->method('getStages')->willReturn($stages);


        $llmClient = $this->createStub(LlmClientInterface::class);
        if ($chatException instanceof \Throwable) {
            $llmClient->method('chat')->willThrowException($chatException);
        } else {
            $llmClient->method('chat')->willReturn([
                'message' => ['role' => 'assistant', 'content' => $llmContent],
            ]);
        }

        // PoiIntentDetector + InRideAssistant narrative both call generate().
        // We return the same `$llmContent` envelope so in-ride tests can drive
        // the intent classifier with the same fixture as planning tests; the
        // narrative call also receives this JSON and falls back to the default
        // markdown narrative if it cannot parse it (acceptable for the in-ride
        // assertion that only inspects `pois`, not the narrative wording).
        $llmClient->method('generate')->willReturn(['response' => $llmContent]);

        $promptLoader = new SystemPromptLoader($this->createPromptFixtureDir());
        $historyStore = new ChatHistoryStore(new ArrayAdapter());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(42);

        $user = new User('chat@example.com', Uuid::v7());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $distance = new HaversineDistance();
        $poiRepository = $this->createStub(InRidePoiRepositoryInterface::class);
        $poiRepository->method('findNearby')->willReturn($inRidePois ?? []);
        $inRideAssistant = new InRideAssistant(
            intentDetector: new PoiIntentDetector(),
            poiRepository: $poiRepository,
            openingHoursParser: new OpeningHoursParser(),
            detourCalculator: new DetourCalculator($distance),
            deeplinkBuilder: new DeeplinkBuilder(),
            distance: $distance,
            promptLoader: $promptLoader,
        );

        $clientFactory = $this->createStub(UserLlmResolverInterface::class);
        $clientFactory->method('forUser')->willReturn(new ResolvedLlmClient($llmClient, AiProvider::ANTHROPIC));

        return new TripChatProcessor(
            tripStateManager: $repository,
            clientFactory: $clientFactory,
            promptLoader: $promptLoader,
            interpreter: new ChatActionInterpreter(new NullLogger()),
            historyStore: $historyStore,
            responseParser: new LlmResponseParser(),
            security: $security,
            logger: $logger ?? new NullLogger(),
            messageBus: $messageBus,
            generationTracker: $generationTracker,
            inRideAssistant: $inRideAssistant,
            tripChatLimiter: $limiterFactory ?? $this->newNoLimiterFactory(),
            entityManager: $entityManager,
        );
    }

    private function createPromptFixtureDir(): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'trip-chat-prompts-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'dialogue.txt', 'SYSTEM PROMPT');
        $this->promptFixtureDir = $dir;

        return $dir;
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

    /**
     * @param array<string, mixed> $params
     */
    private function jsonEnvelope(string $action, array $params, string $response): string
    {
        return json_encode([
            'action' => $action,
            'params' => $params,
            'response' => $response,
        ], \JSON_THROW_ON_ERROR);
    }

    private function newNoLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'trip_chat_test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }
}
