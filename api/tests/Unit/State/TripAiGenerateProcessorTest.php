<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\TripAiGenerateRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Llm\AiProvider;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\UserLlmResolverInterface;
use App\Message\GenerateAiRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripAiGenerateProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class TripAiGenerateProcessorTest extends TestCase
{
    #[Test]
    public function dispatchesGenerateAiRouteAndReturnsAPendingTrip(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(configured: true, messageBus: $bus);

        $trip = $processor->process(
            new TripAiGenerateRequest('boucle au départ de Lille, 2 jours, 80 km/jour'),
            new Post(),
        );

        self::assertArrayHasKey('route', $trip->computationStatus);
        self::assertSame('pending', $trip->computationStatus['route']);

        $messages = $this->collectDispatched($bus, GenerateAiRoute::class);
        self::assertCount(1, $messages);
        self::assertSame($trip->id, $messages[0]->tripId);
        self::assertSame('boucle au départ de Lille, 2 jours, 80 km/jour', $messages[0]->brief);
        self::assertSame(1, $messages[0]->generation);
    }

    #[Test]
    public function throws422WhenNoProviderIsConfigured(): void
    {
        $bus = $this->newMessageBus();
        $processor = $this->newProcessor(configured: false, messageBus: $bus);

        try {
            $processor->process(new TripAiGenerateRequest('boucle au départ de Lille'), new Post());
            self::fail('Expected UnprocessableEntityHttpException.');
        } catch (UnprocessableEntityHttpException) {
            // No trip is created and no generation is dispatched for an unconfigured user.
            self::assertCount(0, $this->collectDispatched($bus, GenerateAiRoute::class));
        }
    }

    #[Test]
    public function throws429WhenRateLimitExceeded(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'ai_generate_test_429', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $processor = $this->newProcessor(configured: true, messageBus: $this->newMessageBus(), limiterFactory: $factory);

        // First call consumes the only token.
        $processor->process(new TripAiGenerateRequest('boucle au départ de Lille'), new Post());

        try {
            $processor->process(new TripAiGenerateRequest('boucle au départ de Tours'), new Post());
            self::fail('Expected TooManyRequestsHttpException.');
        } catch (TooManyRequestsHttpException $tooManyRequestsHttpException) {
            // The 429 must carry a Retry-After header so clients can back off.
            self::assertArrayHasKey('Retry-After', $tooManyRequestsHttpException->getHeaders());
            self::assertGreaterThanOrEqual(0, $tooManyRequestsHttpException->getHeaders()['Retry-After']);
        }
    }

    private function newProcessor(
        bool $configured,
        MessageBusInterface $messageBus,
        ?RateLimiterFactory $limiterFactory = null,
    ): TripAiGenerateProcessor {
        $user = new User('gen@example.com', Uuid::v7());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $clientFactory = $this->createStub(UserLlmResolverInterface::class);
        $clientFactory->method('forUser')->willReturn(
            $configured ? new ResolvedLlmClient($this->createStub(LlmClientInterface::class), AiProvider::ANTHROPIC) : null,
        );

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/trips/ai-generate'));

        return new TripAiGenerateProcessor(
            messageBus: $messageBus,
            tripStateManager: $this->createStub(TripRequestRepositoryInterface::class),
            computationTracker: $this->createStub(ComputationTrackerInterface::class),
            generationTracker: $this->createStub(TripGenerationTrackerInterface::class),
            clientFactory: $clientFactory,
            requestStack: $requestStack,
            security: $security,
            tripStateCache: new ArrayAdapter(),
            aiGenerateLimiter: $limiterFactory ?? $this->newNoLimiterFactory(),
        );
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
            ['id' => 'ai_generate_test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }
}
