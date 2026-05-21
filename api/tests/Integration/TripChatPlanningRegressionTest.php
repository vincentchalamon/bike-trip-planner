<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRideAssistant;
use App\InRide\OpeningHoursParser;
use App\InRide\PoiIntentDetector;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\OsmOverpassQueryBuilder;
use App\Scanner\ScannerInterface;
use App\State\TripChatProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Regression: when {@see TripChatRequest::$position} is null, the planning
 * pipeline introduced in sprint 31 must keep working unchanged — the seven
 * planning actions are still dispatched (or flagged as full-analysis /
 * info) and the in-ride assistant must NOT be invoked.
 */
final class TripChatPlanningRegressionTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000300';

    private string $promptFixtureDir = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ('' === $this->promptFixtureDir || !is_dir($this->promptFixtureDir)) {
            return;
        }

        foreach (glob($this->promptFixtureDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->promptFixtureDir);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: bool, 3: bool, 4: list<int>}>
     */
    public static function planningActionsProvider(): iterable
    {
        yield 'split_stage' => ['split_stage', ['stage' => 3], true, false, [3, 4]];
        yield 'merge_stages' => ['merge_stages', ['stages' => [2, 3]], true, false, [2]];
        yield 'add_waypoint' => ['add_waypoint', ['name' => 'Mont Cassel', 'stage' => 4], true, false, [4]];
        yield 'change_accommodation' => ['change_accommodation', ['stage' => 2, 'type' => 'guest_house'], true, false, [2, 3]];
        yield 'adjust_distance' => ['adjust_distance', ['stage' => 5, 'km' => 95], true, false, [5]];
        yield 'change_route' => ['change_route', [], false, true, []];
        yield 'info' => ['info', [], false, false, []];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<int>            $expectedImpactedStageNumbers
     */
    #[Test]
    #[DataProvider('planningActionsProvider')]
    public function planningActionsStillWorkWithoutPosition(
        string $action,
        array $params,
        bool $expectedDispatched,
        bool $expectedRequiresFullAnalysis,
        array $expectedImpactedStageNumbers,
    ): void {
        $bus = $this->newMessageBus();

        $scanner = $this->createMock(ScannerInterface::class);
        // In planning mode the in-ride scanner must never be queried.
        $scanner->expects($this->never())->method('query');

        $envelope = json_encode([
            'action' => $action,
            'params' => $params,
            'response' => 'OK',
        ], \JSON_THROW_ON_ERROR);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('isEnabled')->willReturn(true);
        $llm->method('chat')->willReturn([
            'message' => ['role' => 'assistant', 'content' => $envelope],
        ]);
        // generate() belongs to the in-ride pipeline — never expected here.
        $llm->expects($this->never())->method('generate');

        $processor = $this->newProcessor($llm, $scanner, $bus);

        $response = $processor->process(
            new TripChatRequest(message: 'message planning'),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame($action, $response->action);
        self::assertSame($expectedDispatched, $response->dispatched);
        self::assertSame($expectedRequiresFullAnalysis, $response->requiresFullAnalysis);
        self::assertSame($expectedImpactedStageNumbers, $response->impactedStageNumbers);
        self::assertNull($response->pois, 'pois must be null in planning mode');

        $dispatched = $this->collectDispatched($bus, RecalculateStages::class);
        if ($expectedDispatched) {
            self::assertCount(1, $dispatched);
            self::assertTrue($dispatched[0]->skipAiAnalysis);
        } else {
            self::assertCount(0, $dispatched);
        }
    }

    private function newProcessor(
        LlmClientInterface $llm,
        ScannerInterface $scanner,
        MessageBusInterface $messageBus,
    ): TripChatProcessor {
        $tripRequest = new TripRequest();
        $stages = [];
        for ($i = 1; $i <= 5; ++$i) {
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

        $promptLoader = new SystemPromptLoader($this->createPromptFixtureDir());
        $historyStore = new ChatHistoryStore(new ArrayAdapter());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(1);

        $user = new User('chat@example.com', Uuid::v7());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $distance = new HaversineDistance();
        $inRideAssistant = new InRideAssistant(
            intentDetector: new PoiIntentDetector($llm),
            scanner: $scanner,
            queryBuilder: new OsmOverpassQueryBuilder(),
            openingHoursParser: new OpeningHoursParser(),
            detourCalculator: new DetourCalculator($distance),
            deeplinkBuilder: new DeeplinkBuilder(),
            distance: $distance,
            llmClient: $llm,
            promptLoader: $promptLoader,
            cache: new ArrayAdapter(),
        );

        return new TripChatProcessor(
            tripStateManager: $repository,
            llmClient: $llm,
            promptLoader: $promptLoader,
            interpreter: new ChatActionInterpreter(new NullLogger()),
            historyStore: $historyStore,
            security: $security,
            logger: new NullLogger(),
            messageBus: $messageBus,
            generationTracker: $generationTracker,
            inRideAssistant: $inRideAssistant,
            tripChatLimiter: $this->newNoLimiterFactory(),
        );
    }

    private function createPromptFixtureDir(): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'trip-chat-planning-prompts-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'dialogue.txt', 'SYSTEM PROMPT');
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'in-ride.txt', 'Respond with markdown narrative.');
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

    private function newNoLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'trip_chat_planning_test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }
}
