<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Llm\AiProvider;
use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\UserLlmResolverInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripChatTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000099';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('chat@example.com');
    }

    private function seedTrip(string $tripId): void
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    /**
     * Overrides the per-user resolver: a non-null client is wrapped as the
     * user's configured provider; null simulates "AI not configured".
     */
    private function installLlmClient(?LlmClientInterface $client): void
    {
        $resolver = new readonly class ($client) implements UserLlmResolverInterface {
            public function __construct(private ?LlmClientInterface $client)
            {
            }

            public function forUser(User $user): ?ResolvedLlmClient
            {
                return $this->client instanceof LlmClientInterface ? new ResolvedLlmClient($this->client, AiProvider::ANTHROPIC) : null;
            }
        };

        self::getContainer()->set(UserLlmResolverInterface::class, $resolver);
    }

    #[Test]
    public function chatReturnsParsedActionAndResponse(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient([
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'action' => 'split_stage',
                    'params' => ['stage' => 3],
                    'response' => "Très bien, je découpe l'étape 3 en deux.",
                ], \JSON_THROW_ON_ERROR),
            ],
        ]));

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => [
                    'message' => "Coupe l'étape 3 en deux",
                    'context' => ['currentStage' => 3],
                ],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray(false);
        $this->assertSame('split_stage', $data['action']);
        $this->assertSame(['stage' => 3], $data['params']);
        $this->assertStringContainsString('étape 3', $data['response']);
        // The functional fixture has no stages, so the recompute guard short-circuits
        // and no Messenger message is dispatched. The dispatch path itself is covered
        // by TripChatProcessorTest with a seeded stage list.
        $this->assertFalse($data['dispatched']);
        $this->assertSame([], $data['impactedStageNumbers']);
        $this->assertFalse($data['requiresFullAnalysis']);
    }

    #[Test]
    public function chatInfoActionDoesNotMarkAsDispatched(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient([
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'action' => 'info',
                    'params' => [],
                    'response' => 'Le gravel désigne un type de vélo.',
                ], \JSON_THROW_ON_ERROR),
            ],
        ]));

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => "C'est quoi le gravel ?"],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray(false);
        $this->assertSame('info', $data['action']);
        $this->assertFalse($data['dispatched']);
        $this->assertSame([], $data['impactedStageNumbers']);
        $this->assertFalse($data['requiresFullAnalysis']);
    }

    #[Test]
    public function chatChangeRouteActionFlagsFullAnalysis(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient([
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'action' => 'change_route',
                    'params' => [],
                    'response' => 'Cette modification touche tout le tracé.',
                ], \JSON_THROW_ON_ERROR),
            ],
        ]));

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => "Change l'itinéraire pour passer par la côte"],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray(false);
        $this->assertSame('change_route', $data['action']);
        $this->assertFalse($data['dispatched']);
        $this->assertSame([], $data['impactedStageNumbers']);
        $this->assertTrue($data['requiresFullAnalysis']);
    }

    #[Test]
    public function chatReturnsInfoHintWhenAiNotConfigured(): void
    {
        $this->seedTrip(self::TRIP_ID);

        // No provider configured for the user → degrade gracefully with an
        // in-chat `info` hint (200), not a hard error.
        $this->installLlmClient(null);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertSame('info', $data['action']);
        $this->assertStringContainsString('Configurez une IA', $data['response']);
    }

    #[Test]
    public function chatReturns503WhenClientReturnsNull(): void
    {
        $this->seedTrip(self::TRIP_ID);

        // Edge case: a configured client returns null. The dedicated 503 wording
        // for this branch is otherwise untested.
        $this->installLlmClient(new FakeLlmClient());

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function chatReturns503WhenProviderUnreachable(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient(throwUnavailable: true));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function chatReturns422WithInvalidTokenErrorWhenTokenRejected(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient(throwUnavailable: true, unavailableReason: AiFailureReason::INVALID_TOKEN));

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        // #761: an invalid token is now an actionable 422 with a discrete error
        // code (the UI surfaces a settings CTA) instead of a misleading 503.
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ai_invalid_token', $response->getContent(false));
    }

    #[Test]
    public function chatPropagatesRetryAfterHeader(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient(throwUnavailable: true, unavailableReason: AiFailureReason::RATE_LIMITED, retryAfter: 60));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        // #761: a provider rate-limit maps to 429 (mirrors TripAiChatProcessor),
        // propagating the upstream Retry-After hint.
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHeaderSame('Retry-After', '60');
    }

    #[Test]
    public function chatRejectsUnauthenticatedRequests(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            ['json' => ['message' => 'Bonjour']],
        );

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function chatReturns404ForUnknownTrip(): void
    {
        $this->installLlmClient(null);

        $this->client->request(
            'POST',
            '/trips/00000000-0000-0000-0000-000000000000/ai-chat',
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function chatRejectsBlankMessage(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installLlmClient(new FakeLlmClient([
            'message' => ['role' => 'assistant', 'content' => '{}'],
        ]));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/ai-chat', self::TRIP_ID),
            [
                'json' => ['message' => ''],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(422);
    }
}

/**
 * Minimal in-memory LlmClient used by the chat functional test.
 */
final readonly class FakeLlmClient implements LlmClientInterface
{
    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct(
        private ?array $response = null,
        private bool $throwUnavailable = false,
        private AiFailureReason $unavailableReason = AiFailureReason::UNAVAILABLE,
        private ?int $retryAfter = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(string $model, string $prompt, ?string $systemPrompt = null, array $options = []): ?array
    {
        return $this->respond();
    }

    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): ?array
    {
        return $this->respond();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function respond(): ?array
    {
        if ($this->throwUnavailable) {
            throw new AiUnavailableException('Simulated outage.', $this->unavailableReason, $this->retryAfter);
        }

        return $this->response;
    }
}
