<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Contracts\HttpClient\ResponseInterface;
use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Message\FetchAndParseRoute;
use App\Repository\IdempotencyKeyRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `POST /trips` replayed under the same key answers with the trip it already made (ADR-077).
 *
 * Without it, a retry after a dropped response produced a second complete trip — new row, new
 * pipeline, new Redis keys — and nothing in the database stood in the way: the identifier is
 * minted by the server, and there is no unique constraint a second identical creation would
 * violate. Only the per-user rate limiter slowed it down, which caps the rate of duplicates
 * rather than preventing any.
 */
#[ResetDatabase]
final class TripCreationIdempotencyTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string KEY = 'a-key-minted-once-per-intent';

    private const string SOURCE = 'https://www.komoot.com/tour/123456789';

    private Client $client;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt('idempotent@example.com');
    }

    #[Test]
    public function thesameKeyReplayedAnswersWithTheSameTrip(): void
    {
        $first = $this->create(self::KEY, self::SOURCE);
        $this->assertResponseStatusCodeSame(202);
        $this->assertCount(1, $this->routeParsesDispatched(), 'The first call must start the pipeline.');

        $second = $this->create(self::KEY, self::SOURCE);
        $this->assertResponseStatusCodeSame(202);

        $this->assertSame($first->toArray(false)['id'], $second->toArray(false)['id']);

        // The duplicate that mattered was never the row, it was the work behind it. Counted per
        // request because BrowserKit reboots the kernel between the two, which resets the
        // in-memory transport along with it.
        $this->assertCount(0, $this->routeParsesDispatched(), 'The replay must start nothing.');
    }

    #[Test]
    public function theSameKeyWithADifferentBodyIsRefused(): void
    {
        $this->create(self::KEY, self::SOURCE);
        $this->assertResponseStatusCodeSame(202);

        $this->create(self::KEY, 'https://www.komoot.com/tour/987654321');

        $this->assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function aMissingKeyIsRefused(): void
    {
        $this->client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => ['sourceUrl' => self::SOURCE],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function aMalformedKeyIsRefused(): void
    {
        $this->create('too-short', self::SOURCE);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * The key belongs to the client that minted it. Scoping it to the user is what stops two
     * clients that happened to pick the same string from being handed each other's trip.
     */
    #[Test]
    public function theSameKeyFromAnotherUserCreatesItsOwnTrip(): void
    {
        $mine = $this->create(self::KEY, self::SOURCE);
        $this->assertResponseStatusCodeSame(202);

        ['token' => $otherToken] = $this->createTestUserWithJwt('someone-else@example.com');
        $theirs = $this->client->request('POST', '/trips', [
            'headers' => array_merge(
                ['Content-Type' => 'application/ld+json', 'Idempotency-Key' => self::KEY],
                $this->authHeader($otherToken),
            ),
            'json' => ['sourceUrl' => self::SOURCE],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertNotSame($mine->toArray(false)['id'], $theirs->toArray(false)['id']);
    }

    #[Test]
    public function keysPastTheRetentionWindowArePurged(): void
    {
        $this->create(self::KEY, self::SOURCE);

        $repository = self::getContainer()->get(IdempotencyKeyRepository::class);
        \assert($repository instanceof IdempotencyKeyRepository);

        $this->assertSame(0, $repository->purgeOlderThan(new \DateTimeImmutable('-24 hours')));
        $this->assertSame(1, $repository->purgeOlderThan(new \DateTimeImmutable('+1 hour')));
    }

    /**
     * @return list<Envelope>
     */
    private function routeParsesDispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof FetchAndParseRoute,
        ));
    }

    private function create(string $key, string $sourceUrl): ResponseInterface
    {
        return $this->client->request('POST', '/trips', [
            'headers' => array_merge(
                ['Content-Type' => 'application/ld+json', 'Idempotency-Key' => $key],
                $this->authHeader($this->jwtToken),
            ),
            'json' => ['sourceUrl' => $sourceUrl],
        ]);
    }
}
