<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\BelongsToATripGeneration;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\Messenger\StaleMessageMiddleware;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The enrichments a stage generation hands off must survive the staleness guard.
 *
 * The generation a message carries is the trip's structural version (ADR-066), and writing the
 * stages bumps that version. A worker that paces the stages and then dispatches the enrichments
 * with the generation its own message carried stamps them one below the version it just wrote,
 * and {@see StaleMessageMiddleware} drops every one of them (ADR-073).
 *
 * Driven through the real bus, the real handlers and the real middleware; only the route
 * source's HTTP client is doubled.
 */
final class TripEnrichmentGenerationTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    #[Test]
    public function aTripCreatedFromAUrlKeepsItsEnrichments(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set('strava.client', new MockHttpClient(static fn (): MockResponse => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/multi-stage-route.gpx'))));
        ['jwt' => $token] = $this->createAuthenticatedUser(\sprintf('enrichment-generation-%s@test.com', bin2hex(random_bytes(6))));

        $client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'enrichment-generation-'.bin2hex(random_bytes(6))], $this->authHeader($token)),
            'json' => ['sourceUrl' => \sprintf('https://www.strava.com/routes/%d', random_int(1_000_000, 9_999_999))],
        ]);
        $this->assertResponseStatusCodeSame(202);


        $this->consume(FetchAndParseRoute::class);
        $this->consume(GenerateStages::class);

        $this->assertEnrichmentsAreNotStale();
    }

    #[Test]
    public function aRepacedTripKeepsItsEnrichments(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set('strava.client', new MockHttpClient(static fn (): MockResponse => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/multi-stage-route.gpx'))));
        ['jwt' => $token] = $this->createAuthenticatedUser(\sprintf('enrichment-repace-%s@test.com', bin2hex(random_bytes(6))));

        $response = $client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'enrichment-repace-'.bin2hex(random_bytes(6))], $this->authHeader($token)),
            'json' => ['sourceUrl' => \sprintf('https://www.strava.com/routes/%d', random_int(1_000_000, 9_999_999))],
        ]);
        $this->assertResponseStatusCodeSame(202);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);

        $this->consume(FetchAndParseRoute::class);
        $this->consume(GenerateStages::class);
        $this->transport()->reset();

        $version = self::getContainer()->get(TripGenerationTrackerInterface::class)->current($tripId);
        self::assertIsInt($version);

        $client->request('PATCH', '/trips/'.$tripId, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json', 'If-Match' => \sprintf('"%d"', $version)], $this->authHeader($token)),
            'json' => ['fatigueFactor' => 0.7],
        ]);
        $this->assertResponseIsSuccessful();

        $this->consume(GenerateStages::class);

        $this->assertEnrichmentsAreNotStale();
    }

    /**
     * Delivers the queued messages of one class as a worker would, through the whole bus.
     *
     * @param class-string $class
     */
    private function consume(string $class): void
    {
        $transport = $this->transport();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $delivered = 0;
        foreach ([...$transport->get(\PHP_INT_MAX)] as $envelope) {
            if (!$envelope->getMessage() instanceof $class) {
                continue;
            }

            $transport->ack($envelope);
            $bus->dispatch($envelope->with(new ReceivedStamp('async'), new ConsumedByWorkerStamp()));
            ++$delivered;
        }

        self::assertGreaterThan(0, $delivered, \sprintf('No %s was queued.', $class));
    }

    /**
     * Runs every queued enrichment through the real staleness guard, stopping short of the
     * handlers: those call third parties, and the question is only whether they are reached.
     */
    private function assertEnrichmentsAreNotStale(): void
    {
        $middleware = self::getContainer()->get(StaleMessageMiddleware::class);
        self::assertInstanceOf(StaleMessageMiddleware::class, $middleware);

        $reached = new class () implements MiddlewareInterface {
            /** @var list<string> */
            public array $messages = [];

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->messages[] = $envelope->getMessage()::class;

                return $envelope;
            }
        };

        $enrichments = [];
        foreach ($this->transport()->get(\PHP_INT_MAX) as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof BelongsToATripGeneration || $message instanceof FetchAndParseRoute || $message instanceof GenerateStages) {
                continue;
            }

            $enrichments[] = $message::class;
            $middleware->handle($envelope->with(new ConsumedByWorkerStamp()), new StackMiddleware($reached));
        }

        self::assertNotEmpty($enrichments, 'The stage generation dispatched no enrichment.');
        self::assertSame($enrichments, $reached->messages, 'The staleness guard dropped enrichments the stage generation had just dispatched.');
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
