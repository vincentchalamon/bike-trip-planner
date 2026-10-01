<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ComputationTracker\TripGenerationTrackerInterface;
use App\EventListener\ComputationFailureSubscriber;
use App\Message\AllEnrichmentsCompleted;
use App\Message\BelongsToATripGeneration;
use ApiPlatform\Test\Client;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An edit announces the trip complete once, after the enrichments it re-ran have settled.
 *
 * Driven through the real endpoints, the real bus, its middlewares and the real handlers; only
 * the third-party HTTP clients are doubled. Every message is consumed in queue order, as a
 * single worker would, and each `AllEnrichmentsCompleted` is checked against what was still
 * queued when the gate closed.
 *
 * The status map normally lives in Redis for half an hour. The test environment backs it with
 * an array pool the kernel empties between requests, which is that half hour elapsing on every
 * request; here it is kept across requests, so a test chooses when it lapses.
 */
final class TripCompletionGateFlowTest extends ApiTestCase
{
    use AddressesStagesByIdTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    private ArrayAdapter $tripStateCache;

    private string $token;

    /** @var list<list<class-string>> the computations still queued each time the gate closed */
    private array $completions = [];

    private int $completionsSeen = 0;

    #[Test]
    public function aSettingsEditCompletesOnceAfterItsEnrichments(): void
    {
        $tripId = $this->analysedTrip();

        $this->patchPacing($tripId);
        $this->consumeAll();

        $this->assertCompletedOnceAtTheEnd($tripId);
    }

    #[Test]
    public function aSettingsEditCompletesOnceAfterItsEnrichmentsOnceTheStatusMapHasExpired(): void
    {
        $tripId = $this->analysedTrip();
        // The status map alone: re-pacing needs the route points, which expire from the same
        // pool and are a defect of their own (#1405).
        $this->tripStateCache->deleteItem(\sprintf('trip.%s.computation_status', $tripId));

        $this->patchPacing($tripId);
        $this->consumeAll();

        $this->assertCompletedOnceAtTheEnd($tripId);
    }

    #[Test]
    public function aStructuralEditCompletesOnceAfterItsEnrichmentsOnceTheCacheHasExpired(): void
    {
        $tripId = $this->analysedTrip();
        $stageId = $this->stageIdAt($tripId, 1);
        $this->tripStateCache->clear();

        $this->client->request('DELETE', \sprintf('/trips/%s/stages/%s', $tripId, $stageId), [
            'headers' => array_merge(['If-Match' => $this->ifMatch($tripId)], $this->authHeader($this->token)),
        ]);
        $this->assertResponseStatusCodeSame(202);
        $this->consumeAll();

        $this->assertCompletedOnceAtTheEnd($tripId);
    }

    #[Test]
    public function aBatchRecomputeCompletesOnceAfterItsEnrichmentsOnceTheCacheHasExpired(): void
    {
        $tripId = $this->analysedTrip();
        $this->tripStateCache->clear();

        $this->client->request('POST', \sprintf('/trips/%s/recompute', $tripId), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'If-Match' => '*'], $this->authHeader($this->token)),
            'json' => ['modifications' => [['type' => 'pacing']]],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $this->consumeAll();

        $this->assertCompletedOnceAtTheEnd($tripId);
    }

    /**
     * A trip created from a URL and analysed to the end, its own completion already counted.
     */
    private function analysedTrip(): string
    {
        $client = self::createClient();
        $this->client = $client;
        $client->disableReboot();

        $container = self::getContainer();

        $this->tripStateCache = new class () extends ArrayAdapter {
            #[\Override]
            public function reset(): void
            {
            }
        };
        $container->set('cache.trip_state', new TraceableAdapter($this->tripStateCache));
        $container->set('strava.client', new MockHttpClient(static fn (): MockResponse => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/multi-stage-route.gpx'))));
        foreach (['nominatim.client', 'open_meteo.client', 'routing.client'] as $id) {
            $container->set($id, new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')));
        }

        ['jwt' => $this->token] = $this->createAuthenticatedUser(\sprintf('completion-gate-%s@test.com', bin2hex(random_bytes(6))));

        // Dated, so that every computation of the pipeline is dispatched and the first
        // generation can settle at all.
        $response = $client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'completion-gate-'.bin2hex(random_bytes(6))], $this->authHeader($this->token)),
            'json' => [
                'sourceUrl' => \sprintf('https://www.strava.com/routes/%d', random_int(1_000_000, 9_999_999)),
                'startDate' => new \DateTimeImmutable('today +3 days')->format(\DateTimeInterface::ATOM),
                'endDate' => new \DateTimeImmutable('today +8 days')->format(\DateTimeInterface::ATOM),
            ],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);

        $this->consumeAll();
        self::assertSame([[]], $this->completions, 'The first analysis did not complete once.');
        $this->completions = [];

        return $tripId;
    }

    private function patchPacing(string $tripId): void
    {
        $this->client->request('PATCH', '/trips/'.$tripId, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json', 'If-Match' => $this->ifMatch($tripId)], $this->authHeader($this->token)),
            'json' => ['fatigueFactor' => 0.7],
        ]);
        $this->assertResponseIsSuccessful();
    }

    private function ifMatch(string $tripId): string
    {
        return \sprintf('"%d"', self::getContainer()->get(TripGenerationTrackerInterface::class)->current($tripId));
    }

    /**
     * Delivers every queued trip message, one at a time and in order, through the whole bus.
     *
     * The terminal message itself stays on the queue: its handler only publishes, and counting
     * it is the point.
     */
    private function consumeAll(): void
    {
        $this->recordCompletions();

        $transport = $this->transport();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        while (($envelope = $this->nextTripMessage()) instanceof Envelope) {
            $transport->ack($envelope);
            $bus->dispatch($envelope->with(new ReceivedStamp('async'), new ConsumedByWorkerStamp()));
            $this->recordCompletions();
        }
    }

    private function nextTripMessage(): ?Envelope
    {
        foreach ($this->transport()->get(\PHP_INT_MAX) as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof BelongsToATripGeneration && !$message instanceof AllEnrichmentsCompleted) {
                return $envelope;
            }
        }

        return null;
    }

    private function recordCompletions(): void
    {
        $sent = \count($this->completionsSent());

        for ($i = $this->completionsSeen; $i < $sent; ++$i) {
            $queued = [];
            foreach ($this->transport()->get(\PHP_INT_MAX) as $envelope) {
                $message = $envelope->getMessage();
                if (isset(ComputationFailureSubscriber::MESSAGE_TO_COMPUTATION[$message::class])) {
                    $queued[] = $message::class;
                }
            }

            $this->completions[] = $queued;
        }

        $this->completionsSeen = $sent;
    }

    private function assertCompletedOnceAtTheEnd(string $tripId): void
    {
        self::assertSame([[]], $this->completions, 'The edit must complete exactly once, with no computation still queued. Queued at each completion: '.json_encode($this->completions));

        $last = array_last($this->completionsSent());
        self::assertInstanceOf(AllEnrichmentsCompleted::class, $last);
        self::assertSame(self::getContainer()->get(TripGenerationTrackerInterface::class)->current($tripId), $last->generation);
    }

    /** @return list<AllEnrichmentsCompleted> */
    private function completionsSent(): array
    {
        $completions = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof AllEnrichmentsCompleted) {
                $completions[] = $message;
            }
        }

        return $completions;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
