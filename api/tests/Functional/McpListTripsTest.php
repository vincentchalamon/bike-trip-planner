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
 * `list_trips`, and with it the one assumption the whole of unit 3B rests on.
 *
 * A tool's arguments reach neither the query string, nor `$context['filters']`, nor a request
 * body: `ApiPlatform\Mcp\Server\Handler` puts them in `$context['mcp_data']` and stops. If
 * that context did not reach a provider declared on the tool, there would be no way to carry
 * a page number, a version, an idempotency key or a confirmation token over this transport,
 * and every write tool would have to be redesigned around a `kernel.request` listener parsing
 * the JSON-RPC body itself.
 *
 * `testAPageArgumentIsHonoured` is that measurement. It is a pagination test by accident; it
 * is the transport gate on purpose.
 */
#[ResetDatabase]
final class McpListTripsTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private Client $client;

    private User $owner;

    private ?string $ownerToken = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function theToolListsTheCallersTrips(): void
    {
        $this->seedTrips(3);

        $titles = $this->titlesFrom($this->list([]));

        self::assertSame(['Trip 3', 'Trip 2', 'Trip 1'], $titles, 'Most recently created first.');
    }

    /**
     * THE GATE. A `page` argument only reaches `Pagination` if `$context['mcp_data']` reaches
     * the provider — nothing else carries it on this transport. If this test fails, 3B does
     * not proceed as planned.
     */
    #[Test]
    public function aPageArgumentIsHonoured(): void
    {
        $this->seedTrips(3);

        self::assertSame(['Trip 3', 'Trip 2'], $this->titlesFrom($this->list(['itemsPerPage' => 2])));
        self::assertSame(['Trip 1'], $this->titlesFrom($this->list(['page' => 2, 'itemsPerPage' => 2])));
    }

    /**
     * The same bridge serves the filters the HTTP operation already documented, so a tool does
     * not get a second, narrower query language that would drift from the endpoint's.
     */
    #[Test]
    public function theExistingFiltersWorkFromArgumentsToo(): void
    {
        $this->seedTrips(3);

        self::assertSame(['Trip 2'], $this->titlesFrom($this->list(['title' => 'rip 2'])));
    }

    /**
     * The maximum comes from `api_platform.php`, not from anything re-implemented here: a
     * provider that clamped the limit itself would be free to drift away from the value the
     * REST contract publishes. Seeded past the cap on purpose — with three trips this
     * assertion would hold no matter what the limit did, which is the shape of the two
     * vacuous assertions unit 3A shipped and had to come back for.
     */
    #[Test]
    public function theItemsPerPageMaximumStillApplies(): void
    {
        $this->seedTrips(31);

        self::assertCount(30, $this->list(['itemsPerPage' => 5000]));
    }

    #[Test]
    public function anotherUsersTripsAreNotListed(): void
    {
        $this->seedTrips(2);
        $intruder = $this->createTestUserWithJwt('intruder@example.com')['user'];

        self::assertSame([], $this->titlesFrom($this->list([], $this->issueAccessTokenFor($intruder))));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array<array-key, mixed>>
     */
    private function list(array $arguments, ?string $bearer = null): array
    {
        // Issued once and kept. The test kernel resets services after each request, so the
        // seeded User is detached by the time a second call would re-issue for it, and
        // Doctrine refuses to cascade-persist it through RefreshToken#user. Issued lazily and
        // only when no other credential was handed in, for the same reason.
        $bearer ??= $this->ownerToken ??= $this->issueAccessTokenFor($this->owner);

        $response = $this->call(
            $this->rpc('tools/call', ['name' => 'list_trips', 'arguments' => $arguments]),
            'tools/call',
            $bearer,
            'list_trips',
        );

        $envelope = $response->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $members = $envelope['result']['structuredContent']['member'] ?? [];
        self::assertIsArray($members);

        $rows = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            $rows[] = $member;
        }

        return $rows;
    }

    /**
     * @param list<array<array-key, mixed>> $members
     *
     * @return list<string>
     */
    private function titlesFrom(array $members): array
    {
        return array_map(
            static fn (array $member): string => \is_string($member['title'] ?? null) ? $member['title'] : '',
            $members,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(array $body, string $method, ?string $bearer, string $name): ResponseInterface
    {
        return $this->client->request('POST', '/mcp', [
            'headers' => array_filter([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => $method,
                'Mcp-Name' => $name,
                'Authorization' => null === $bearer ? null : 'Bearer '.$bearer,
            ]),
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params + [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ];
    }

    private function seedTrips(int $count): void
    {
        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);

        for ($i = 1; $i <= $count; ++$i) {
            $id = \sprintf('01936f6e-0000-7000-8000-00000000%04d', $i);
            $request = new TripRequest();
            $request->sourceUrl = 'https://www.komoot.com/tour/12345678'.$i;

            $repo->initializeTrip($id, $request);
            $repo->storeTitle($id, 'Trip '.$i);
            $this->associateTripWithUser($id, $this->owner);
        }
    }
}
