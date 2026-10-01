<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Stage;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\Repository\TripStageStoreInterface;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A trip stays re-pacable after its route points have left the cache (#1405).
 *
 * The raw and decimated points live in `cache.trip_state` for half an hour and are never
 * refreshed. Re-pacing a trip past that used to generate no stage at all, and the empty list
 * replaced every stage the trip had.
 *
 * The test environment backs that pool with an array the kernel empties between requests;
 * here it is kept across requests, so the test chooses when the half hour has passed.
 */
final class RepacingAfterPointsExpireTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    private ArrayAdapter $tripStateCache;

    private string $token;

    #[Test]
    public function aTripCreatedFromAUrlIsRepacedFromItsStagesOnceItsPointsHaveExpired(): void
    {
        $this->boot();
        self::getContainer()->set('strava.client', new MockHttpClient(static fn (): MockResponse => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/multi-stage-route.gpx'))));

        $response = $this->client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'repacing-'.bin2hex(random_bytes(6))], $this->authHeader($this->token)),
            'json' => ['sourceUrl' => \sprintf('https://www.strava.com/routes/%d', random_int(1_000_000, 9_999_999))],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);
        $this->consume();

        $this->assertRepacedOnceThePointsHaveExpired($tripId);
    }

    #[Test]
    public function anUploadedGpxIsRepacedFromItsStagesOnceItsPointsHaveExpired(): void
    {
        $this->boot();

        $response = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->token)),
            'extra' => [
                'files' => ['gpxFile' => new UploadedFile(__DIR__.'/../fixtures/multi-stage-route.gpx', 'multi-stage-route.gpx', 'application/gpx+xml', null, true)],
            ],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);

        $this->assertRepacedOnceThePointsHaveExpired($tripId);
    }

    /**
     * A duplicate copies the points that are still cached and nothing once they have expired:
     * its own stages are then the only route it has.
     */
    #[Test]
    public function aTripDuplicatedOnceItsPointsHaveExpiredIsRepacedFromItsStages(): void
    {
        $this->boot();

        $response = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->token)),
            'extra' => [
                'files' => ['gpxFile' => new UploadedFile(__DIR__.'/../fixtures/multi-stage-route.gpx', 'multi-stage-route.gpx', 'application/gpx+xml', null, true)],
            ],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $sourceId = $response->toArray(false)['id'];
        self::assertIsString($sourceId);
        $this->tripStateCache->clear();

        $response = $this->client->request('POST', \sprintf('/trips/%s/duplicate', $sourceId), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'repacing-duplicate-'.bin2hex(random_bytes(6))], $this->authHeader($this->token)),
        ]);
        $this->assertResponseStatusCodeSame(201);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);

        $this->assertRepacedOnceThePointsHaveExpired($tripId);
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tripStateCache = new class () extends ArrayAdapter {
            #[\Override]
            public function reset(): void
            {
            }
        };
        self::getContainer()->set('cache.trip_state', new TraceableAdapter($this->tripStateCache));

        ['jwt' => $this->token] = $this->createAuthenticatedUser(\sprintf('repacing-%s@test.com', bin2hex(random_bytes(6))));
    }

    private function assertRepacedOnceThePointsHaveExpired(string $tripId): void
    {
        $before = $this->stages($tripId);
        self::assertGreaterThanOrEqual(2, \count($before), 'The trip was not paced in the first place.');

        $this->tripStateCache->clear();

        $version = self::getContainer()->get(TripGenerationTrackerInterface::class)->current($tripId);
        $this->client->request('PATCH', '/trips/'.$tripId, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json', 'If-Match' => \sprintf('"%d"', $version)], $this->authHeader($this->token)),
            'json' => ['maxDistancePerDay' => 40],
        ]);
        $this->assertResponseIsSuccessful();
        $this->consume();

        $after = $this->stages($tripId);
        self::assertGreaterThanOrEqual(2, \count($after), 'Re-pacing destroyed the stages.');
        self::assertNotSame(\count($before), \count($after), 'The new pacing was not applied.');
        self::assertEqualsWithDelta($this->totalDistance($before), $this->totalDistance($after), 0.02 * $this->totalDistance($before), 'The re-paced stages do not cover the same route.');
    }

    /** @return list<Stage> */
    private function stages(string $tripId): array
    {
        return self::getContainer()->get(TripStageStoreInterface::class)->getStages($tripId) ?? [];
    }

    /** @param list<Stage> $stages */
    private function totalDistance(array $stages): float
    {
        return array_sum(array_map(static fn (Stage $stage): float => $stage->distance, $stages));
    }

    /**
     * Delivers the queued route and stage messages through the whole bus, as a worker would.
     * The enrichments are left alone: the question is only what the stages become.
     */
    private function consume(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        do {
            $delivered = 0;
            foreach ([...$transport->get(\PHP_INT_MAX)] as $envelope) {
                $message = $envelope->getMessage();
                if (!$message instanceof GenerateStages && !$message instanceof FetchAndParseRoute) {
                    continue;
                }

                $transport->ack($envelope);
                $bus->dispatch($envelope->with(new ReceivedStamp('async'), new ConsumedByWorkerStamp()));
                ++$delivered;
            }
        } while ($delivered > 0);
    }
}
