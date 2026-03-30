<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\IdempotencyCheckerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripUpdateTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000001';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(
        string $tripId,
        ?string $sourceUrl = 'https://www.komoot.com/tour/123456789',
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        float $fatigueFactor = 0.9,
        float $elevationPenalty = 50.0,
    ): void {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = $sourceUrl;
        $request->startDate = $startDate;
        $request->endDate = $endDate;
        $request->fatigueFactor = $fatigueFactor;
        $request->elevationPenalty = $elevationPenalty;

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip($tripId, $request);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::pipeline());

        /** @var IdempotencyCheckerInterface $idempotencyChecker */
        $idempotencyChecker = $container->get(IdempotencyCheckerInterface::class);
        $idempotencyChecker->saveHash($tripId, $request);
        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function updateTripSuccess(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 0.8,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content
    }

    #[Test]
    public function updateTripResponseContainsId(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'elevationPenalty' => 40.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function tripNotFound(): void
    {
        $this->client->request('PATCH', '/trips/nonexistent-trip-id', [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 0.8,
            ],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 404,
            'detail' => 'Trip "nonexistent-trip-id" not found or has expired.',
        ]);
    }

    #[Test]
    public function rejectsHttpSourceUrl(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'http://www.komoot.com/tour/123',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'sourceUrl'],
            ],
        ]);
    }

    #[Test]
    public function acceptsAnyHttpsSourceUrl(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://unknown-provider.example.com/route/123',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    #[Test]
    public function rejectsFatigueFactorTooLow(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 0.3,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'fatigueFactor'],
            ],
        ]);
    }

    #[Test]
    public function rejectsFatigueFactorTooHigh(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 1.5,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'fatigueFactor'],
            ],
        ]);
    }

    #[Test]
    public function rejectsNegativeElevationPenalty(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'elevationPenalty' => -10.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'elevationPenalty'],
            ],
        ]);
    }

    #[Test]
    public function rejectsZeroElevationPenalty(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'elevationPenalty' => 0.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'elevationPenalty'],
            ],
        ]);
    }

    #[Test]
    public function rejectsEndDateBeforeStartDate(): void
    {
        $this->seedTrip(
            self::TRIP_ID,
            startDate: new \DateTimeImmutable('2026-07-15'),
        );

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'endDate' => '2026-07-01T00:00:00+00:00',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'endDate'],
            ],
        ]);
    }

    #[Test]
    public function rejectsEndDateEqualToStartDate(): void
    {
        $this->seedTrip(
            self::TRIP_ID,
            startDate: new \DateTimeImmutable('2026-07-15'),
        );

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'endDate' => '2026-07-15T00:00:00+00:00',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'endDate'],
            ],
        ]);
    }

    #[Test]
    public function updateTitleSuccess(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'title' => 'Mon super voyage',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $request = $repo->getRequest(self::TRIP_ID);
        $this->assertSame('Mon super voyage', $request?->title);
    }

    #[Test]
    public function idempotentRequestDoesNotRedispatch(): void
    {
        $this->seedTrip(self::TRIP_ID, fatigueFactor: 0.9, elevationPenalty: 50.0);

        // Send PATCH with same values as seeded
        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 0.9,
                'elevationPenalty' => 50.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();
        $this->assertCount(0, $sentMessages, 'Idempotent request should not dispatch any messages.');
    }

    #[Test]
    public function sourceUrlChangeTriggersRouteComputation(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/999999999',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();

        $this->assertCount(1, $sentMessages);
        $this->assertInstanceOf(FetchAndParseRoute::class, $sentMessages[0]->getMessage());
    }

    #[Test]
    public function fatigueFactorChangeTriggersStagesComputation(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'fatigueFactor' => 0.75,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();

        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $sentMessages,
        );

        $this->assertContains(GenerateStages::class, $messageClasses);
    }

    #[Test]
    public function startDateChangeTriggersWeatherAndCalendar(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'startDate' => '2026-08-01T00:00:00+00:00',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));
        // todo check response content

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();

        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $sentMessages,
        );

        $this->assertContains(FetchWeather::class, $messageClasses);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPatchPayloadProvider(): iterable
    {
        yield 'fatigueFactor below minimum' => [['fatigueFactor' => 0.1], 'fatigueFactor'];
        yield 'fatigueFactor above maximum' => [['fatigueFactor' => 2.0], 'fatigueFactor'];
        yield 'negative elevation penalty' => [['elevationPenalty' => -5.0], 'elevationPenalty'];
        yield 'zero elevation penalty' => [['elevationPenalty' => 0.0], 'elevationPenalty'];
        yield 'invalid URL protocol' => [['sourceUrl' => 'http://www.komoot.com/tour/123'], 'sourceUrl'];
        yield 'title too long' => [['title' => str_repeat('a', 256)], 'title'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPatchPayloadProvider')]
    #[Test]
    public function rejectsInvalidPatchPayloads(array $payload, string $expectedPropertyPath): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json'], $this->authHeader($this->jwtToken)),
            'json' => $payload,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => $expectedPropertyPath],
            ],
        ]);
    }
}
