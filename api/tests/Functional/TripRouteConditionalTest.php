<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `GET /trips/{id}/route` is the one resource whose ETag is a representation validator.
 *
 * Its body is a day number and a geometry per stage, and both are written only by
 * `storeStages()`, which always bumps `trip.version`. Everything else carrying that version —
 * `/trips/{id}/detail` above all — is answered `no-store`, because the eight targeted
 * enrichment writes rewrite those bodies without moving the version (ADR-078).
 *
 * The two negative cases are the load-bearing ones: a conditional answer is an authorization
 * decision, so a 304 must never be reachable where the full response would be a 404.
 */
#[ResetDatabase]
final class TripRouteConditionalTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000a01';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerToken] = $this->createTestUserWithJwt('route-owner@example.com');
    }

    /**
     * Authenticated responses are marked private, and what they vary on is `Accept`.
     *
     * Measured rather than configured: a probe value placed in `cache_headers.vary` never
     * reaches the wire on any operation, because `RespondProcessor` sets `Vary: Accept` when
     * it builds the response and that is what survives. `public => false` does work, and it
     * is the half that matters — nothing carried a cache directive at all before it, on
     * responses that do carry an ETag.
     */
    #[Test]
    public function authenticatedResponsesAreMarkedPrivateAndVaryOnAccept(): void
    {
        $this->seedTrip();

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->ownerToken)),
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString('Accept', $this->header($response, 'vary'));
        $this->assertStringContainsString('private', $this->header($response, 'cache-control'));

        $session = $this->client->request('GET', '/auth/session', ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertStringContainsString('private', $this->header($session, 'cache-control'));
    }

    #[Test]
    public function aMatchingValidatorIsAnsweredNotModified(): void
    {
        $this->seedTrip();

        $etag = $this->routeEtag();
        $this->assertNotSame('', $etag);
        $this->assertResponseHeaderSame('cache-control', 'no-cache, private');

        $conditional = $this->client->request('GET', $this->routeUrl(), [
            'headers' => array_merge(
                ['Accept' => 'application/ld+json', 'If-None-Match' => $etag],
                $this->authHeader($this->ownerToken),
            ),
        ]);

        $this->assertResponseStatusCodeSame(304);
        // AddHeadersProcessor bails on a non-2xx response, so a 304 carries what it carries
        // only because the provider set it (RFC 9110 §15.4.5).
        $this->assertResponseHeaderSame('etag', $etag);
        $this->assertResponseHeaderSame('cache-control', 'no-cache, private');
        $this->assertStringContainsString('Accept', $this->header($conditional, 'vary'));
    }

    /**
     * The invariant the 304 rests on, in both directions.
     *
     * Regenerating the stages moves the geometry, so the validator must move with it. A
     * weather enrichment rewrites `/detail` but cannot touch a day number or a coordinate, so
     * the validator must not move — otherwise every worker that finishes would throw away a
     * client's map cache for nothing.
     */
    #[Test]
    public function theValidatorTracksTheGeometryAndNothingElse(): void
    {
        $repo = $this->seedTrip();
        $before = $this->routeEtag();

        $stageId = ($repo->getStages(self::TRIP_ID) ?? [])[0]->id;
        $repo->updateStageWeather(self::TRIP_ID, $stageId, new WeatherForecast(
            icon: 'sun',
            description: 'Clear',
            tempMin: 8.0,
            tempMax: 21.0,
            windSpeed: 12.0,
            windDirection: 'NW',
            precipitationProbability: 10,
            humidity: 60,
            comfortIndex: 4,
            relativeWindDirection: 'tailwind',
        ));

        $this->assertSame($before, $this->routeEtag(), 'An enrichment invalidated the map cache.');

        $repo->storeStages(self::TRIP_ID, [$this->stage(46.0)]);

        $this->assertNotSame($before, $this->routeEtag(), 'Regenerating the stages left a stale validator.');
    }

    #[Test]
    public function aValidatorFromAnotherUsersTripIsStillNotFound(): void
    {
        $this->seedTrip();
        $etag = $this->routeEtag();

        ['token' => $intruderToken] = $this->createTestUserWithJwt('route-intruder@example.com');

        $this->client->request('GET', $this->routeUrl(), [
            'headers' => array_merge(
                ['Accept' => 'application/ld+json', 'If-None-Match' => $etag],
                $this->authHeader($intruderToken),
            ),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * The anonymous share honours the validator too — it delegates to the same provider.
     *
     * Worth its own case because the delegation is typed: a return type narrowed to
     * `TripRoute` would let the authenticated route answer 304 while this one blew up on a
     * `Response` it says it cannot return.
     */
    #[Test]
    public function theSharedRouteIsAlsoAnsweredNotModified(): void
    {
        $this->seedTrip();
        $sharedUrl = $this->shareUrl();

        $response = $this->client->request('GET', $sharedUrl, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $etag = $this->header($response, 'etag');
        $this->assertNotSame('', $etag);

        $this->client->request('GET', $sharedUrl, [
            'headers' => ['Accept' => 'application/ld+json', 'If-None-Match' => $etag],
        ]);

        $this->assertResponseStatusCodeSame(304);
        $this->assertResponseHeaderSame('etag', $etag);
    }

    /**
     * Confirming a cached copy is an authorization decision.
     *
     * Revocation is enforced by `findByShortCode()` filtering on `deletedAt IS NULL`, inside
     * `TripShareRouteProvider`. Answering the conditional request ahead of that — which a
     * decorator of the read provider would have done — tells a client holding a stale copy
     * that a revoked link is still current.
     */
    #[Test]
    public function aValidatorAgainstARevokedShareIsStillNotFound(): void
    {
        $this->seedTrip();
        $sharedUrl = $this->shareUrl();

        $response = $this->client->request('GET', $sharedUrl, ['headers' => ['Accept' => 'application/ld+json']]);
        $this->assertResponseIsSuccessful();
        $etag = $this->header($response, 'etag');
        $this->assertNotSame('', $etag);

        $this->client->request('DELETE', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => $this->authHeader($this->ownerToken),
        ]);
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', $sharedUrl, [
            'headers' => ['Accept' => 'application/ld+json', 'If-None-Match' => $etag],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * The other half of ADR-078: `/detail` carries the same number and must never honour it.
     */
    #[Test]
    public function theDetailIsNeverAnsweredNotModified(): void
    {
        $this->seedTrip();

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->ownerToken)),
        ]);
        $this->assertResponseIsSuccessful();
        $etag = $this->header($response, 'etag');

        $again = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(
                ['Accept' => 'application/ld+json', 'If-None-Match' => $etag],
                $this->authHeader($this->ownerToken),
            ),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('no-store', $this->header($again, 'cache-control'));
    }

    private function seedTrip(): TripRequestRepositoryInterface
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repo->initializeTrip(self::TRIP_ID, $request);
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);
        $repo->storeStages(self::TRIP_ID, [$this->stage(45.0)]);

        return $repo;
    }

    private function stage(float $lat): StageDto
    {
        return new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate($lat, 6.0, 1000.0),
            endPoint: new Coordinate($lat + 0.5, 6.5, 800.0),
            geometry: [new Coordinate($lat, 6.0, 1000.0), new Coordinate($lat + 0.5, 6.5, 800.0)],
        );
    }

    private function routeUrl(): string
    {
        return \sprintf('/trips/%s/route', self::TRIP_ID);
    }

    private function shareUrl(): string
    {
        $share = $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->ownerToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);
        /** @var array{shortCode: string} $body */
        $body = $share->toArray();

        return \sprintf('/s/%s/route', $body['shortCode']);
    }

    private function routeEtag(): string
    {
        $response = $this->client->request('GET', $this->routeUrl(), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->ownerToken)),
        ]);
        $this->assertResponseIsSuccessful();

        return $this->header($response, 'etag');
    }

    /**
     * The last response's header. `getHeaders(false)` because a 304 is not 2xx and the client
     * would otherwise throw on it.
     */
    private function header(ResponseInterface $response, string $name): string
    {
        return $response->getHeaders(false)[$name][0] ?? '';
    }
}
