<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use PHPUnit\Framework\Attributes\Test;

final class TripCreateTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt(\sprintf('%s@test.com', bin2hex(random_bytes(8))));
    }

    #[Test]
    public function createTripWithKomootTourUrl(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function createTripWithKomootCollectionUrl(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/collection/12345/my-collection',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function createTripWithLocalizedKomootUrl(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/fr-fr/tour/123456789',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function createTripWithAllOptionalFields(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
                'startDate' => '2026-07-01T00:00:00+00:00',
                'endDate' => '2026-07-15T00:00:00+00:00',
                'fatigueFactor' => 0.85,
                'elevationPenalty' => 40.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function allComputationsInitializedAsPending(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        foreach (ComputationName::pipeline() as $computation) {
            $this->assertArrayHasKey($computation->value, $data['computationStatus']);
            $this->assertSame('pending', $data['computationStatus'][$computation->value]);
        }
    }

    #[Test]
    public function fetchAndParseRouteMessageDispatched(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();

        $this->assertCount(1, $sentMessages);
        $this->assertInstanceOf(FetchAndParseRoute::class, $sentMessages[0]->getMessage());
    }

    #[Test]
    public function rejectsMissingSourceUrl(): void
    {
        self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => new \stdClass(),
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
    public function rejectsEmptySourceUrl(): void
    {
        self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => '',
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
    public function rejectsNullSourceUrl(): void
    {
        self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => null,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
    }

    /**
     * The POST operation uses the 'trip_request:create' validation group,
     * which only validates NotBlank on sourceUrl. URL format, range, and
     * positive constraints are in the Default group (validated on PATCH only).
     * Invalid URL formats are caught asynchronously during route fetching.
     */
    #[Test]
    public function acceptsInvalidUrlFormatOnCreate(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://example.com/not-a-real-route',
            ],
        ]);

        // POST only validates NotBlank(groups: ['trip_request:create'])
        // URL format validation is in Default group, not triggered here
        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validPayloadProvider(): iterable
    {
        yield 'minimal with Komoot tour' => [[
            'sourceUrl' => 'https://www.komoot.com/tour/999',
        ]];

        yield 'with start date only' => [[
            'sourceUrl' => 'https://www.komoot.com/tour/999',
            'startDate' => '2026-08-01T00:00:00+00:00',
        ]];

        yield 'with custom fatigue factor' => [[
            'sourceUrl' => 'https://www.komoot.com/tour/999',
            'fatigueFactor' => 0.5,
        ]];

        yield 'with custom elevation penalty' => [[
            'sourceUrl' => 'https://www.komoot.com/tour/999',
            'elevationPenalty' => 100.0,
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('validPayloadProvider')]
    #[Test]
    public function createTripWithValidPayloads(array $payload): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => $payload,
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/trip-schema.json'));

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function theTripLocaleComesFromTheAccountNotFromAcceptLanguage(): void
    {
        // ADR-063: the locale is a stored account preference, not a property of
        // whichever transport happens to carry the request. A non-browser client
        // (an agent, a cron, the mobile app) sends no Accept-Language at all, and
        // must not silently fall back to a different language than the owner's.
        ['user' => $user, 'token' => $token] = $this->createTestUserWithJwt('locale-owner@test.com');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $user->setLocale('en');
        $em->flush();

        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(
                [
                    'Content-Type' => 'application/ld+json',
                    // Deliberately contradicts the account preference.
                    'Accept-Language' => 'fr',
                ],
                $this->authHeader($token),
            ),
            'json' => ['sourceUrl' => 'https://www.komoot.com/tour/123456789'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $tripId = $response->toArray(false)['id'];
        $this->assertIsString($tripId);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $this->assertSame(
            'en',
            $repo->getLocale($tripId),
            'The trip took the Accept-Language header instead of the account preference.',
        );
    }
}
