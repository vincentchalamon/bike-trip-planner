<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Llm\LlmAnalysisTrackerInterface;
use App\Llm\LlmClientInterface;
use App\Llm\StageAnalysisSummaryBuilder;
use App\Llm\SystemPromptLoader;
use App\Mercure\MercureEventType;
use App\Mercure\StagePayloadMapper;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeStageWithLlmMessage;
use App\Message\AnalyzeTripOverviewWithLlmMessage;
use App\MessageHandler\AllEnrichmentsCompletedHandler;
use App\MessageHandler\AnalyzeStageWithLlmHandler;
use App\MessageHandler\AnalyzeTripOverviewWithLlmHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * End-to-end pipeline test for the LLaMA 8B analysis chain (issue #303).
 *
 * Exercises the three handlers in sequence:
 *
 *   AllEnrichmentsCompletedHandler
 *   → N × AnalyzeStageWithLlmHandler (pass 1, parallel)
 *   → AnalyzeTripOverviewWithLlmHandler (pass 2)
 *   → TRIP_READY published once with the fully enriched payload
 *
 * The Messenger bus is replaced with an in-memory dispatcher that immediately
 * invokes the matching handler so the test can run synchronously and assert
 * end-to-end side effects: pass-1 analyses, pass-2 overview, single TRIP_READY
 * publication, and progress events.
 *
 * Both Ollama-enabled (full pipeline) and Ollama-disabled (short-circuit) flows
 * are covered.
 */
final class LlmPipelineTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private string $tmpPromptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpPromptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'llm-pipeline-prompts-'.bin2hex(random_bytes(4));

        if (!mkdir($this->tmpPromptDir, 0o755, true) && !is_dir($this->tmpPromptDir)) {
            throw new \RuntimeException('Failed to create tmp prompt dir.');
        }

        $placeholders = "Region: {{region}}\nProfile: {{rider_profile}}\nLanguage: {{language}}\nDate: {{date}}\n";
        file_put_contents(
            $this->tmpPromptDir.\DIRECTORY_SEPARATOR.AnalyzeStageWithLlmHandler::PROMPT_NAME.'.txt',
            $placeholders,
        );
        file_put_contents(
            $this->tmpPromptDir.\DIRECTORY_SEPARATOR.AnalyzeTripOverviewWithLlmHandler::PROMPT_NAME.'.txt',
            $placeholders,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->tmpPromptDir)) {
            return;
        }

        foreach (glob($this->tmpPromptDir.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpPromptDir);
    }

    #[Test]
    public function fullPipelineEnrichmentsToLlmToTripReady(): void
    {
        $stages = [
            $this->makeStage(dayNumber: 1, distance: 80.0),
            $this->makeStage(dayNumber: 2, distance: 0.0, isRestDay: true),
            $this->makeStage(dayNumber: 3, distance: 95.0),
        ];

        $repository = new InMemoryTripRequestRepository(
            stages: $stages,
            request: $this->makeTripRequest(),
        );

        $publisher = new RecordingTripUpdatePublisher();
        $llmTracker = new InMemoryLlmAnalysisTracker();
        $bus = new InMemoryBus();

        $stageHandler = new AnalyzeStageWithLlmHandler(
            tripStateManager: $repository,
            llmClient: $this->stageLlmClient(),
            promptLoader: new SystemPromptLoader($this->tmpPromptDir),
            summaryBuilder: new StageAnalysisSummaryBuilder(),
            logger: new NullLogger(),
            llmTracker: $llmTracker,
            messageBus: $bus,
            publisher: $publisher,
        );

        $overviewHandler = new AnalyzeTripOverviewWithLlmHandler(
            tripStateManager: $repository,
            llmClient: $this->overviewLlmClient(),
            promptLoader: new SystemPromptLoader($this->tmpPromptDir),
            logger: new NullLogger(),
            llmTracker: $llmTracker,
            computationTracker: $this->stubComputationTracker(['route' => 'done', 'weather' => 'failed']),
            publisher: $publisher,
        );

        $bus->register(
            AnalyzeStageWithLlmMessage::class,
            static function (object $message) use ($stageHandler): void {
                \assert($message instanceof AnalyzeStageWithLlmMessage);
                $stageHandler($message);
            },
        );
        $bus->register(
            AnalyzeTripOverviewWithLlmMessage::class,
            static function (object $message) use ($overviewHandler): void {
                \assert($message instanceof AnalyzeTripOverviewWithLlmMessage);
                $overviewHandler($message);
            },
        );

        // The terminal handler shares the LLM tracker with both downstream handlers
        // so the gate→pass1→pass2→TRIP_READY flow is observable in-memory.
        $terminalHandler = new AllEnrichmentsCompletedHandler(
            computationTracker: $this->stubComputationTracker(['route' => 'done']),
            publisher: $publisher,
            tripRequestRepository: $repository,
            logger: new NullLogger(),
            messageBus: $bus,
            llmClient: $this->stageLlmClient(),
            llmTracker: $llmTracker,
        );

        // Trigger the pipeline.
        $terminalHandler(new AllEnrichmentsCompleted(self::TRIP_ID));

        // Pass-1: each non-rest stage received an aiAnalysis.
        self::assertNotNull($repository->stageAiAnalysisFor(1), 'Stage 1 should have a pass-1 analysis.');
        self::assertNotNull($repository->stageAiAnalysisFor(3), 'Stage 3 should have a pass-1 analysis.');
        self::assertNull($repository->stageAiAnalysisFor(2), 'Rest stage 2 must not be analysed.');

        // Pass-2: trip-level overview was persisted.
        self::assertNotNull($repository->tripAiOverview(), 'Pass-2 trip overview should be persisted.');

        // Exactly one TRIP_READY was published — the terminal one from the overview handler.
        $tripReadyEvents = $publisher->byType(MercureEventType::TRIP_READY);
        self::assertCount(1, $tripReadyEvents, 'TRIP_READY must be published exactly once.');

        // The payload must contain stages with ai_analysis and the ai_overview.
        $tripReady = $tripReadyEvents[0];
        self::assertSame(self::TRIP_ID, $tripReady['tripId']);
        self::assertArrayHasKey('stages', $tripReady['data']);
        self::assertIsArray($tripReady['data']['stages']);
        self::assertCount(3, $tripReady['data']['stages']);
        self::assertIsArray($tripReady['data']['stages'][0]);
        self::assertNotNull($tripReady['data']['stages'][0]['aiAnalysis']);
        self::assertIsArray($tripReady['data']['stages'][2]);
        self::assertNotNull($tripReady['data']['stages'][2]['aiAnalysis']);
        self::assertIsArray($tripReady['data']['stages'][1]);
        self::assertNull($tripReady['data']['stages'][1]['aiAnalysis'], 'Rest stage payload carries no ai_analysis.');
        self::assertArrayHasKey('aiOverview', $tripReady['data']);
        self::assertIsArray($tripReady['data']['aiOverview']);
        self::assertNotSame('', $tripReady['data']['aiOverview']['narrative']);

        // AI progress events fired (initial + per-stage + final overview).
        $progressEvents = $publisher->byType(MercureEventType::COMPUTATION_STEP_COMPLETED);
        $aiProgressEvents = array_values(array_filter(
            $progressEvents,
            static fn (array $event): bool => 'ai_analysis' === ($event['data']['category'] ?? null),
        ));
        self::assertGreaterThanOrEqual(2, \count($aiProgressEvents), 'At least the kick-off and overview AI progress events must fire.');
    }

    #[Test]
    public function pipelineWithoutOllamaShortCircuitsToTripReady(): void
    {
        $stages = [$this->makeStage(dayNumber: 1, distance: 80.0)];

        $repository = new InMemoryTripRequestRepository(
            stages: $stages,
            request: $this->makeTripRequest(),
        );

        $publisher = new RecordingTripUpdatePublisher();

        $bus = new InMemoryBus();

        $disabledLlm = $this->createStub(LlmClientInterface::class);
        $disabledLlm->method('isEnabled')->willReturn(false);

        $terminalHandler = new AllEnrichmentsCompletedHandler(
            computationTracker: $this->stubComputationTracker(['route' => 'done']),
            publisher: $publisher,
            tripRequestRepository: $repository,
            logger: new NullLogger(),
            messageBus: $bus,
            llmClient: $disabledLlm,
            llmTracker: new InMemoryLlmAnalysisTracker(),
        );

        $terminalHandler(new AllEnrichmentsCompleted(self::TRIP_ID));

        // No LLM message dispatched.
        self::assertSame(0, $bus->dispatchCount(), 'No LLM message must be dispatched when Ollama is disabled.');

        // Exactly one TRIP_READY published, no ai_overview.
        $tripReadyEvents = $publisher->byType(MercureEventType::TRIP_READY);
        self::assertCount(1, $tripReadyEvents);

        $tripReady = $tripReadyEvents[0];
        self::assertSame(self::TRIP_ID, $tripReady['tripId']);
        self::assertArrayHasKey('stages', $tripReady['data']);
        self::assertIsArray($tripReady['data']['stages']);
        self::assertCount(1, $tripReady['data']['stages']);
        self::assertArrayNotHasKey('aiOverview', $tripReady['data'], 'Disabled LLM means no aiOverview key.');

        // No AI progress events.
        $aiProgress = array_filter(
            $publisher->byType(MercureEventType::COMPUTATION_STEP_COMPLETED),
            static fn (array $event): bool => 'ai_analysis' === ($event['data']['category'] ?? null),
        );
        self::assertCount(0, $aiProgress, 'AI category must NOT appear when LLM is disabled.');
    }

    #[Test]
    public function pipelineWithOnlyRestDaysShortCircuitsToTripReady(): void
    {
        $stages = [$this->makeStage(dayNumber: 1, distance: 0.0, isRestDay: true)];

        $repository = new InMemoryTripRequestRepository(
            stages: $stages,
            request: $this->makeTripRequest(),
        );

        $publisher = new RecordingTripUpdatePublisher();

        $bus = new InMemoryBus();

        $enabledLlm = $this->createStub(LlmClientInterface::class);
        $enabledLlm->method('isEnabled')->willReturn(true);

        $terminalHandler = new AllEnrichmentsCompletedHandler(
            computationTracker: $this->stubComputationTracker(['route' => 'done']),
            publisher: $publisher,
            tripRequestRepository: $repository,
            logger: new NullLogger(),
            messageBus: $bus,
            llmClient: $enabledLlm,
            llmTracker: new InMemoryLlmAnalysisTracker(),
        );

        $terminalHandler(new AllEnrichmentsCompleted(self::TRIP_ID));

        // Even with LLM enabled, no analysable stage means short-circuit.
        self::assertSame(0, $bus->dispatchCount());
        self::assertCount(1, $publisher->byType(MercureEventType::TRIP_READY));
    }

    // -------------------------------------------------------------------------
    // Helpers / factories
    // -------------------------------------------------------------------------

    private function makeStage(int $dayNumber, float $distance, bool $isRestDay = false): Stage
    {
        return new Stage(
            tripId: self::TRIP_ID,
            dayNumber: $dayNumber,
            distance: $distance,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            isRestDay: $isRestDay,
        );
    }

    private function makeTripRequest(): TripRequest
    {
        $request = new TripRequest();
        $request->locale = 'fr';
        $request->ebikeMode = false;
        $request->startDate = new \DateTimeImmutable('2026-06-01');

        return $request;
    }

    private function stageLlmClient(): LlmClientInterface
    {
        $client = $this->createStub(LlmClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('generate')->willReturn([
            'response' => "## Synthèse\nÉtape exigeante.\n\n## Insights\n- Long sans eau\n\n## Recommandations\n- Faire le plein.\n",
            'done' => true,
        ]);

        return $client;
    }

    private function overviewLlmClient(): LlmClientInterface
    {
        $client = $this->createStub(LlmClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->method('generate')->willReturn([
            'response' => "## Vue d'ensemble\nTrip exigeant.\n\n## Charge et fatigue cumulative\nJ2 plus dure.\n\n## Patterns transversaux\n- Zones sans eau\n\n## Recommandations globales\n- Pause à mi-parcours.\n",
            'done' => true,
        ]);

        return $client;
    }

    /** @param array<string, string> $statuses */
    private function stubComputationTracker(array $statuses): ComputationTrackerInterface
    {
        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('claimReadyPublication')->willReturn(true);
        $tracker->method('getStatuses')->willReturn($statuses);

        return $tracker;
    }
}

/**
 * Minimal in-memory repository covering only the slice of
 * {@see TripRequestRepositoryInterface} exercised by the LLM pipeline.
 */
final class InMemoryTripRequestRepository implements TripRequestRepositoryInterface
{
    /** @var array<int, array{narrative: string, insights: list<string>, suggestions: list<string>, model: string, promptVersion: int, generatedAt: string}|null> */
    private array $stageAiAnalysis = [];

    /** @var array{narrative: string, patterns: list<string>, recommendations: list<string>, crossStageAlerts: list<string>, model: string, promptVersion: int, generatedAt: string}|null */
    private ?array $aiOverview = null;

    /** @param list<Stage> $stages */
    public function __construct(
        private readonly array $stages,
        private readonly TripRequest $request,
    ) {
    }

    public function getStages(string $tripId): array
    {
        return array_map(
            function (Stage $stage): Stage {
                $stage->aiAnalysis = $this->stageAiAnalysis[$stage->dayNumber] ?? null;

                return $stage;
            },
            $this->stages,
        );
    }

    public function getRequest(string $tripId): TripRequest
    {
        return $this->request;
    }

    public function updateStageAiAnalysis(string $tripId, int $dayNumber, ?array $aiAnalysis): void
    {
        $this->stageAiAnalysis[$dayNumber] = $aiAnalysis;
    }

    public function updateTripAiOverview(string $tripId, ?array $aiOverview): void
    {
        $this->aiOverview = $aiOverview;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stageAiAnalysisFor(int $dayNumber): ?array
    {
        return $this->stageAiAnalysis[$dayNumber] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tripAiOverview(): ?array
    {
        return $this->aiOverview;
    }

    // ---- unused interface members ----

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
    }

    public function getTitle(string $tripId): ?string
    {
        return null;
    }

    public function storeTitle(string $tripId, ?string $title): void
    {
    }

    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
    }

    public function getRawPoints(string $tripId): ?array
    {
        return null;
    }

    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
    }

    public function getDecimatedPoints(string $tripId): ?array
    {
        return null;
    }

    public function storeStages(string $tripId, array $stages): void
    {
    }

    public function storeTracksData(string $tripId, array $tracksData): void
    {
    }

    public function getTracksData(string $tripId): ?array
    {
        return null;
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
    }

    public function getSourceType(string $tripId): ?string
    {
        return null;
    }

    public function storeLocale(string $tripId, string $locale): void
    {
    }

    public function getLocale(string $tripId): ?string
    {
        return null;
    }
}

/**
 * In-memory bus that immediately routes the dispatched message to the
 * registered handler. Mirrors Symfony Messenger's sync transport behaviour
 * closely enough for orchestration testing.
 */
final class InMemoryBus implements MessageBusInterface
{
    /** @var array<string, callable(object): void> */
    private array $handlers = [];

    private int $dispatchCount = 0;

    /**
     * @param class-string           $messageClass
     * @param callable(object): void $handler
     */
    public function register(string $messageClass, callable $handler): void
    {
        $this->handlers[$messageClass] = $handler;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        ++$this->dispatchCount;
        $class = $message::class;

        if (isset($this->handlers[$class])) {
            ($this->handlers[$class])($message);
        }

        return new Envelope($message, $stamps);
    }

    public function dispatchCount(): int
    {
        return $this->dispatchCount;
    }
}

/**
 * Captures every Mercure event the pipeline publishes so the test can assert
 * exactly one TRIP_READY and a coherent sequence of progress events.
 */
final class RecordingTripUpdatePublisher implements TripUpdatePublisherInterface
{
    /** @var list<array{tripId: string, type: MercureEventType, data: array<string, mixed>}> */
    private array $events = [];

    private readonly StagePayloadMapper $mapper;

    public function __construct()
    {
        $this->mapper = new StagePayloadMapper();
    }

    /** @param array<string, mixed> $data */
    public function publish(string $tripId, MercureEventType $type, array $data = []): void
    {
        $this->events[] = ['tripId' => $tripId, 'type' => $type, 'data' => $data];
    }

    public function publishValidationError(string $tripId, string $code, string $message): void
    {
    }

    public function publishComputationError(string $tripId, string $computation, string $message, bool $retryable = true): void
    {
    }

    public function publishTripComplete(string $tripId, array $computationStatus): void
    {
    }

    public function publishComputationStepCompleted(
        string $tripId,
        ComputationName $step,
        int $completed,
        int $total,
        int $failed = 0,
    ): void {
        $this->publish($tripId, MercureEventType::COMPUTATION_STEP_COMPLETED, [
            'step' => $step->value,
            'category' => $step->category(),
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    public function publishTripReady(string $tripId, array $stages, array $summary): void
    {
        $data = [
            'stages' => $this->mapper->toPayloadList($stages),
            'computationStatus' => $summary['status'] ?? [],
        ];

        if (\array_key_exists('aiOverview', $summary)) {
            $data['aiOverview'] = $summary['aiOverview'];
        }

        $this->publish($tripId, MercureEventType::TRIP_READY, $data);
    }

    public function publishStageUpdated(string $tripId, Stage $stage): void
    {
    }

    /**
     * @return list<array{tripId: string, type: MercureEventType, data: array<string, mixed>}>
     */
    public function byType(MercureEventType $type): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $event): bool => $event['type'] === $type,
        ));
    }
}

/**
 * In-memory implementation of {@see LlmAnalysisTrackerInterface} for the
 * pipeline test — same semantics as the Redis-backed version, no I/O.
 */
final class InMemoryLlmAnalysisTracker implements LlmAnalysisTrackerInterface
{
    /** @var array<string, array{completed: int, failed: int, total: int}> */
    private array $progress = [];

    /** @var array<string, true> */
    private array $overviewClaims = [];

    /** @var array<string, true> */
    private array $readyClaims = [];

    public function initializeStageAnalyses(string $tripId, int $expectedStages): void
    {
        $this->progress[$tripId] = ['completed' => 0, 'failed' => 0, 'total' => max(0, $expectedStages)];
    }

    public function markStageAnalysisSettled(string $tripId, bool $success): array
    {
        $progress = $this->progress[$tripId] ?? ['completed' => 0, 'failed' => 0, 'total' => 0];

        if ($success) {
            ++$progress['completed'];
        } else {
            ++$progress['failed'];
        }

        $this->progress[$tripId] = $progress;

        return $progress;
    }

    public function getStageAnalysisProgress(string $tripId): ?array
    {
        return $this->progress[$tripId] ?? null;
    }

    public function claimOverviewDispatch(string $tripId): bool
    {
        if (isset($this->overviewClaims[$tripId])) {
            return false;
        }

        $this->overviewClaims[$tripId] = true;

        return true;
    }

    public function claimTripReadyPublication(string $tripId): bool
    {
        if (isset($this->readyClaims[$tripId])) {
            return false;
        }

        $this->readyClaims[$tripId] = true;

        return true;
    }
}
