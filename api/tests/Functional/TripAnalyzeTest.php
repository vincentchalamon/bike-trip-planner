<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `POST /trips/{id}/analyze` queues the whole enrichment pipeline: it must neither be
 * repeatable while one is queued, nor scriptable faster than the workers drain.
 */
#[ResetDatabase]
final class TripAnalyzeTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private Client $client;

    private User $user;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The limiter's array pool dies with the kernel, which the browser reboots between
        // requests unless told not to.
        $this->client->disableReboot();
        ['user' => $this->user, 'jwt' => $this->jwtToken] = $this->createAuthenticatedUser(\sprintf('analyze-%s@test.com', bin2hex(random_bytes(4))));
    }

    #[Test]
    public function aQueuedAnalysisAnswers409(): void
    {
        // The upload dispatched the enrichments; nothing consumes them here, so they are queued.
        $tripId = $this->uploadTrip();

        $this->client->request('POST', \sprintf('/trips/%s/analyze', $tripId), $this->asOwner());

        $this->assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function aSettledTripIsAnalyzedUntilTheBudgetRunsOut(): void
    {
        $tripId = $this->uploadTrip();
        $this->settle($tripId);

        $this->client->request('POST', \sprintf('/trips/%s/analyze', $tripId), $this->asOwner());
        $this->assertResponseStatusCodeSame(202);

        $this->settle($tripId);

        // A fresh kernel: the array pool behind the limiter is reset at the start of every
        // request after the first, so the budget is spent on a kernel that has served none.
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var RateLimiterFactory $factory */
        $factory = self::getContainer()->get('limiter.trip_analyze');
        $limiter = $factory->create($this->user->getId()->toRfc4122());
        while ($limiter->consume()->isAccepted()) {
        }

        $response = $this->client->request('POST', \sprintf('/trips/%s/analyze', $tripId), $this->asOwner());

        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) ($response->getHeaders(false)['retry-after'][0] ?? 0));
    }

    private function settle(string $tripId): void
    {
        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        foreach (ComputationName::pipeline() as $computation) {
            $tracker->markDone($tripId, $computation);
        }
    }

    private function uploadTrip(): string
    {
        $file = new UploadedFile(__DIR__.'/../fixtures/valid-route.gpx', 'valid-route.gpx', 'application/gpx+xml', null, true);

        $response = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => ['files' => ['gpxFile' => $file]],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $id = $response->toArray(false)['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function asOwner(): array
    {
        return ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))];
    }
}
