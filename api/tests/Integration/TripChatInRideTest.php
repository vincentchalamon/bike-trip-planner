<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\GeoPosition;
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
use App\InRide\PoiSuggestion;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Dto\ChatAction;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\OsmOverpassQueryBuilder;
use App\Scanner\ScannerInterface;
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
        $scanner = $this->createMock(ScannerInterface::class);
        $scanner->expects($this->once())->method('query')->willReturn([
            'elements' => [
                [
                    'type' => 'node',
                    'lat' => 50.8504,
                    'lon' => 4.3520,
                    'tags' => ['name' => 'Fontaine du Sablon'],
                ],
                [
                    'type' => 'node',
                    'lat' => 50.8550,
                    'lon' => 4.3600,
                    'tags' => ['name' => 'Fontaine Royale'],
                ],
                [
                    'type' => 'node',
                    'lat' => 50.8600,
                    'lon' => 4.3650,
                    'tags' => ['name' => 'Fontaine de la Place'],
                ],
                [
                    'type' => 'node',
                    'lat' => 50.8700,
                    'lon' => 4.3700,
                    'tags' => ['name' => 'Fontaine du Parc'],
                ],
            ],
        ]);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('isEnabled')->willReturn(true);
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

        $processor = $this->newProcessor($llm, $scanner, $bus);

        $response = $processor->process(
            new TripChatRequest(
                message: "Je cherche un point d'eau",
                context: null,
                position: new GeoPosition(50.8503, 4.3517),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

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
        $scanner = $this->createMock(ScannerInterface::class);
        $scanner->expects($this->never())->method('query');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('isEnabled')->willReturn(true);
        $llm->method('generate')->willReturn([
            'response' => '{"category":"unknown","max_distance_m":3000}',
        ]);

        $processor = $this->newProcessor($llm, $scanner, $this->newMessageBus());

        $response = $processor->process(
            new TripChatRequest(
                message: 'Quelle est la météo ?',
                context: null,
                position: new GeoPosition(50.8503, 4.3517),
            ),
            new Post(),
            ['id' => self::TRIP_ID],
        );

        self::assertSame(ChatAction::ACTION_FIND_POI, $response->action);
        self::assertSame(['category' => PoiSuggestion::CATEGORY_UNKNOWN], $response->params);
        self::assertIsArray($response->pois);
        self::assertSame([], $response->pois);
        self::assertStringContainsString("point d'intérêt", $response->response);
    }

    private function newProcessor(
        LlmClientInterface $llm,
        ScannerInterface $scanner,
        MessageBusInterface $messageBus,
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
