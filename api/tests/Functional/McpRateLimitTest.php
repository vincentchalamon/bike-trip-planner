<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The per-call budget, end to end: spent inside a single request, and answered per call.
 *
 * One `POST /mcp` on the handshake era carries a batch of 61 `get_trip` calls. The budget is
 * sixty a minute per agent, so exactly one of them must be refused — which proves both that the
 * budget is wired into the handler the SDK really calls, and that it counts calls rather than
 * HTTP requests. A limiter on the request would have let all 61 through.
 *
 * ⚠ Why inside one request: the limiter pool is an array adapter under test, and the kernel
 * resets its services when a request begins after an earlier one. Draining the limiter from the
 * test and then calling was measured to test nothing — same limiter object, 59 tokens left by
 * the time the handler read it.
 */
#[ResetDatabase]
final class McpRateLimitTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string HANDSHAKE = '2025-06-18';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009f7';

    private Client $client;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('budget@example.com');
    }

    #[Test]
    public function theSixtyFirstCallOfTheMinuteIsRefusedWhateverEnvelopeItCameIn(): void
    {
        $this->seedTrip();
        $token = $this->issueAccessTokenFor($this->owner, ['trips:read']);
        $session = $this->handshake($token);

        $answers = $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => self::HANDSHAKE,
                'Mcp-Session-Id' => $session,
                'Authorization' => 'Bearer '.$token,
            ],
            'json' => array_map(static fn (int $id): array => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]],
            ], range(1, 61)),
        ])->toArray(false);

        self::assertCount(61, $answers);

        $refused = array_values(array_filter($answers, static fn (mixed $answer): bool => \is_array($answer) && isset($answer['error'])));
        self::assertCount(1, $refused, 'Exactly one call over the budget of sixty.');
        self::assertIsArray($refused[0]);
        self::assertIsArray($error = $refused[0]['error']);
        self::assertIsArray($data = $error['data'] ?? null);
        self::assertSame('rate_limited', $data['error'] ?? null);
        self::assertIsInt($data['retryAfter'] ?? null);
    }

    private function handshake(string $token): string
    {
        $response = $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Authorization' => 'Bearer '.$token,
            ],
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => self::HANDSHAKE,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'probe', 'version' => '1.0.0'],
            ]],
        ]);

        $session = $response->getHeaders(false)['mcp-session-id'][0] ?? null;
        self::assertIsString($session);

        return $session;
    }

    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Traversée du Vercors');
        $repo->storeStatus(self::TRIP_ID, 'ready');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);
    }
}
