<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
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

    private function installFakeLlmClient(LlmClientInterface $client): void
    {
        self::getContainer()->set(LlmClientInterface::class, $client);
    }

    #[Test]
    public function chatReturnsParsedActionAndResponse(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installFakeLlmClient(new FakeLlmClient([
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
            \sprintf('/trips/%s/chat', self::TRIP_ID),
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

        $this->installFakeLlmClient(new FakeLlmClient([
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
            \sprintf('/trips/%s/chat', self::TRIP_ID),
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

        $this->installFakeLlmClient(new FakeLlmClient([
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
            \sprintf('/trips/%s/chat', self::TRIP_ID),
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
    public function chatReturns503WhenLlmDisabled(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installFakeLlmClient(new FakeLlmClient(response: null, enabled: false));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function chatReturns503WhenLlmEnabledButReturnsNull(): void
    {
        $this->seedTrip(self::TRIP_ID);

        // Edge case: client reports as enabled but chat() returns null. The
        // dedicated 503 wording for this branch is otherwise untested.
        $this->installFakeLlmClient(new FakeLlmClient(response: null, enabled: true));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function chatReturns503WhenOllamaUnreachable(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->installFakeLlmClient(new FakeLlmClient(throwUnavailable: true));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/chat', self::TRIP_ID),
            [
                'json' => ['message' => 'Bonjour'],
                'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            ],
        );

        $this->assertResponseStatusCodeSame(503);
    }

    #[Test]
    public function chatRejectsUnauthenticatedRequests(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/chat', self::TRIP_ID),
            ['json' => ['message' => 'Bonjour']],
        );

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function chatReturns404ForUnknownTrip(): void
    {
        $this->installFakeLlmClient(new FakeLlmClient(enabled: false));

        $this->client->request(
            'POST',
            '/trips/00000000-0000-0000-0000-000000000000/chat',
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

        $this->installFakeLlmClient(new FakeLlmClient([
            'message' => ['role' => 'assistant', 'content' => '{}'],
        ]));

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/chat', self::TRIP_ID),
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
        private bool $enabled = true,
        private bool $throwUnavailable = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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
            throw new OllamaUnavailableException('Simulated outage.');
        }

        if (!$this->enabled) {
            return null;
        }

        return $this->response;
    }
}
