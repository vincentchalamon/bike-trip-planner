<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\GeoPosition;
use App\ApiResource\Stage;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripChatResponse;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRideAssistant;
use App\InRide\InRidePoiRepositoryInterface;
use App\InRide\OpeningHoursParser;
use App\InRide\PoiIntentDetector;
use App\InRide\PoiSuggestion;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Dto\ChatAction;
use App\Llm\AiProvider;
use App\Llm\LlmClientInterface;
use App\Llm\LlmResponseParser;
use App\Llm\ResolvedLlmClient;
use App\Llm\UserLlmResolverInterface;
use App\Llm\SystemPromptLoader;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripChatProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
 * Integration: verifies that {@see TripChatProcessor} switches to in-ride
 * mode when a GPS position is present in the payload, delegating to the
 * {@see InRideAssistant} and surfacing the top-3 POI suggestions in the
 * response (issue #463).
 */
#[AllowMockObjectsWithoutExpectations]
final class TripChatInRideTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000200';

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

    #[Test]
    public function positionInPayloadDelegatesToInRideAssistantAndReturnsTopPois(): void
    {
        $bus = $this->newMessageBus();
        $poiRepository = $this->createMock(InRidePoiRepositoryInterface::class);
        $poiRepository->expects($this->once())->method('findNearby')->willReturn([
            ['lat' => 50.8504, 'lon' => 4.3520, 'tags' => ['name' => 'Fontaine du Sablon']],
            ['lat' => 50.8550, 'lon' => 4.3600, 'tags' => ['name' => 'Fontaine Royale']],
            ['lat' => 50.8600, 'lon' => 4.3650, 'tags' => ['name' => 'Fontaine de la Place']],
            ['lat' => 50.8700, 'lon' => 4.3700, 'tags' => ['name' => 'Fontaine du Parc']],
        ]);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static function (string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array {
                // The intent detector sends the raw user message; the narrative
                // generator sends a JSON payload.
                if (str_starts_with(trim($prompt), '{')) {
                    return ['response' => "Voici l'eau la plus proche."];
                }

                return ['response' => '{"category":"water","max_distance_m":3000}'];
            },
        );

        $processor = $this->newProcessor($llm, $poiRepository, $bus);

        $response = $processor->process(
            new TripChatRequest(
                message: "Je cherche un point d'eau",
                position: new GeoPosition(50.8503, 4.3517),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(TripChatResponse::class, $response);
        self::assertSame(ChatAction::ACTION_FIND_POI, $response->action);
        self::assertSame(['category' => PoiSuggestion::CATEGORY_WATER], $response->params);
        self::assertFalse($response->dispatched);
        self::assertSame([], $response->impactedStageNumbers);
        self::assertFalse($response->requiresFullAnalysis);
        self::assertSame("Voici l'eau la plus proche.", $response->response);

        self::assertIsArray($response->pois);
        // Top-3 capped, in proximity order.
        self::assertCount(3, $response->pois);
        self::assertSame('Fontaine du Sablon', $response->pois[0]->name);
        self::assertSame(PoiSuggestion::CATEGORY_WATER, $response->pois[0]->category);

        // No planning action dispatched in in-ride mode.
        self::assertSame([], $this->collectDispatched($bus, RecalculateStages::class));
    }

    #[Test]
    public function unknownIntentInInRideModeReturnsEmptyPoisAndExplanatoryResponse(): void
    {
        $poiRepository = $this->createMock(InRidePoiRepositoryInterface::class);
        $poiRepository->expects($this->never())->method('findNearby');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturn([
            'response' => '{"category":"unknown","max_distance_m":3000}',
        ]);

        $processor = $this->newProcessor($llm, $poiRepository, $this->newMessageBus());

        $response = $processor->process(
            new TripChatRequest(
                message: 'Quelle est la météo ?',
                position: new GeoPosition(50.8503, 4.3517),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertInstanceOf(TripChatResponse::class, $response);
        self::assertSame(ChatAction::ACTION_FIND_POI, $response->action);
        self::assertSame(['category' => PoiSuggestion::CATEGORY_UNKNOWN], $response->params);
        self::assertIsArray($response->pois);
        self::assertSame([], $response->pois);
        self::assertStringContainsString("point d'intérêt", $response->response);
    }

    /**
     * Ensures the in-ride branch reaches the EntityManager so PostgreSQL
     * captures both turns of the consultation (Redis sliding-window context
     * alone disappears after MAX_MESSAGES).
     */
    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function inRideTurnsArePersistedToPostgres(): void
    {
        $persisted = [];

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
        $entityManager->expects(self::once())->method('flush');

        $poiRepository = $this->createMock(InRidePoiRepositoryInterface::class);
        $poiRepository->method('findNearby')->willReturn([]);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturn([
            'response' => '{"category":"unknown","max_distance_m":3000}',
        ]);

        $processor = $this->newProcessor($llm, $poiRepository, $this->newMessageBus(), $entityManager);

        $processor->process(
            new TripChatRequest(
                message: 'Point eau ?',
                position: new GeoPosition(50.8503, 4.3517),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertCount(2, $persisted, 'In-ride turns must persist both user prompt and assistant reply.');
    }

    private function newProcessor(
        LlmClientInterface $llm,
        InRidePoiRepositoryInterface $poiRepository,
        MessageBusInterface $messageBus,
        ?EntityManagerInterface $entityManager = null,
    ): TripChatProcessor {
        $tripRequest = new TripRequest();
        $stages = [
            new Stage(
                tripId: self::TRIP_ID,
                dayNumber: 1,
                distance: 50.0,
                elevation: 200.0,
                startPoint: new Coordinate(48.0, 2.0, 0.0),
                endPoint: new Coordinate(48.1, 2.1, 0.0),
            ),
        ];

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
            intentDetector: new PoiIntentDetector(),
            poiRepository: $poiRepository,
            openingHoursParser: new OpeningHoursParser(),
            detourCalculator: new DetourCalculator($distance),
            deeplinkBuilder: new DeeplinkBuilder(),
            distance: $distance,
            promptLoader: $promptLoader,
        );

        $clientFactory = $this->createStub(UserLlmResolverInterface::class);
        $clientFactory->method('forUser')->willReturn(new ResolvedLlmClient($llm, AiProvider::ANTHROPIC));

        return new TripChatProcessor(
            tripStateManager: $repository,
            clientFactory: $clientFactory,
            promptLoader: $promptLoader,
            interpreter: new ChatActionInterpreter(new NullLogger()),
            historyStore: $historyStore,
            responseParser: new LlmResponseParser(),
            security: $security,
            logger: new NullLogger(),
            messageBus: $messageBus,
            generationTracker: $generationTracker,
            inRideAssistant: $inRideAssistant,
            tripChatLimiter: $this->newNoLimiterFactory(),
            entityManager: $entityManager,
        );
    }

    private function createPromptFixtureDir(): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'trip-chat-in-ride-prompts-'.bin2hex(random_bytes(4));
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
            ['id' => 'trip_chat_in_ride_test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }
}
