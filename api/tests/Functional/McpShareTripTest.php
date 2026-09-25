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
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The first two tools that write, and the two-call shape the destructive one takes.
 *
 * `share_trip` answers with the address rather than the row: an agent does not know where the
 * application is served from, so a short code alone is of no use to the person it is talking
 * to. It is also how the downloads excluded from the MCP surface stay reachable — `/s/{code}.gpx`
 * needs no token, so the link is handed over and the human downloads the file.
 *
 * `unshare_trip` is the first tool to go through the confirmation short circuit, and the
 * assertion that matters is not that a token is returned: it is that nothing was written on
 * the call that only asked.
 */
#[ResetDatabase]
final class McpShareTripTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009b1';

    private Client $client;

    private User $owner;

    private ?string $ownerToken = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.mcp_confirmation')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
        $this->seedTrip();
    }

    #[Test]
    public function sharingAnswersWithAnAddressAPersonCanOpen(): void
    {
        $link = $this->structured($this->tool('share_trip', ['tripId' => self::TRIP_ID]));

        self::assertIsString($link['shortCode'] ?? null);
        self::assertIsString($link['url'] ?? null);
        self::assertStringEndsWith('/s/'.$link['shortCode'], $link['url']);

        // The address is the whole point: it has to resolve for someone holding no credentials.
        self::assertSame(200, $this->anonymous('/s/'.$link['shortCode'])->getStatusCode());
    }

    /** Asked twice means asked twice: the first call is a question, not a quiet rehearsal. */
    #[Test]
    public function revokingAsksFirstAndChangesNothing(): void
    {
        $shortCode = $this->share();

        $challenge = $this->structured($this->tool('unshare_trip', ['tripId' => self::TRIP_ID]));

        self::assertTrue($challenge['confirmationRequired'] ?? null);
        self::assertIsString($challenge['confirmationToken'] ?? null);
        self::assertNotSame('', $challenge['confirmationToken']);

        $impact = $challenge['impact'] ?? null;
        self::assertIsArray($impact);
        self::assertSame('Traversée du Vercors', $impact['title']);
        self::assertTrue($impact['hasActiveShareLink']);

        self::assertSame(200, $this->anonymous('/s/'.$shortCode)->getStatusCode(), 'The link is gone: the call that only asked wrote something.');
    }

    #[Test]
    public function theTokenRevokesAndIsSpentOnce(): void
    {
        $shortCode = $this->share();

        $challenge = $this->structured($this->tool('unshare_trip', ['tripId' => self::TRIP_ID]));
        $token = $challenge['confirmationToken'];
        self::assertIsString($token);

        $done = $this->structured($this->tool('unshare_trip', ['tripId' => self::TRIP_ID, 'confirmationToken' => $token]));
        self::assertIsString($done['result'] ?? null);

        self::assertSame(404, $this->anonymous('/s/'.$shortCode)->getStatusCode(), 'The link still resolves after the link was revoked.');

        // Replayed, the token buys nothing. It is spent whether or not it matched, so a second
        // destructive call has to go round the loop again.
        $replay = $this->tool('unshare_trip', ['tripId' => self::TRIP_ID, 'confirmationToken' => $token])->toArray(false);
        self::assertArrayHasKey('error', $replay);
    }

    /**
     * The hard prerequisite of the whole surface, rejoined on every tool: a trip belonging to
     * someone else and a trip that does not exist must answer identically, body included.
     * `TripVoter` refuses both, and the expression names the URI variable so it is evaluated
     * before any provider can report absence.
     */
    #[Test]
    public function anotherUsersTripIsIndistinguishableFromNoTripAtAll(): void
    {
        ['user' => $stranger] = $this->createTestUserWithJwt('stranger@example.com');
        $strangerToken = $this->issueAccessTokenFor($stranger, ['trips:write']);

        $someoneElses = $this->tool('share_trip', ['tripId' => self::TRIP_ID], $strangerToken)->toArray(false);
        $nothingAtAll = $this->tool('share_trip', ['tripId' => '01936f6e-0000-7000-8000-00000000dead'], $strangerToken)->toArray(false);

        self::assertSame($someoneElses['error'] ?? null, $nothingAtAll['error'] ?? null);
        self::assertArrayHasKey('error', $someoneElses);
        self::assertStringNotContainsString('Vercors', json_encode($someoneElses, \JSON_THROW_ON_ERROR));
    }

    private function share(): string
    {
        $link = $this->structured($this->tool('share_trip', ['tripId' => self::TRIP_ID]));
        self::assertIsString($link['shortCode'] ?? null);

        return $link['shortCode'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function structured(ResponseInterface $response): array
    {
        $envelope = $response->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $content = $envelope['result']['structuredContent'] ?? null;
        self::assertIsArray($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $name, array $arguments, ?string $bearer = null): ResponseInterface
    {
        $bearer ??= $this->ownerToken ??= $this->issueAccessTokenFor($this->owner, ['trips:write']);

        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => $name,
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $name,
                    'arguments' => $arguments,
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function anonymous(string $path): ResponseInterface
    {
        return $this->client->request('GET', $path, ['headers' => ['Accept' => 'application/ld+json']]);
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
