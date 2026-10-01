<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Mercure\MercureSubscriberListener;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Which responses carry the browser's Mercure subscription cookie, end to end: the trip id
 * comes from the provider or processor that served the request, through
 * {@see \App\Mercure\TripSubscription}, and {@see MercureSubscriberListener} only reads it.
 */
final class MercureSubscriptionCookieTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string COOKIE = '__Secure-mercure_access_token';

    private Client $client;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['jwt' => $this->jwtToken] = $this->createAuthenticatedUser(\sprintf('mercure-cookie-%s@test.com', bin2hex(random_bytes(4))));
    }

    #[Test]
    public function everyTripEntryPointSubscribesTheBrowser(): void
    {
        $upload = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => ['files' => ['gpxFile' => new UploadedFile(__DIR__.'/../fixtures/valid-route.gpx', 'valid-route.gpx', 'application/gpx+xml', null, true)]],
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertTrue($this->hasSubscriptionCookie($upload), 'POST /trips/gpx-upload');
        $tripId = (string) $upload->toArray(false)['id'];

        $detail = $this->client->request('GET', \sprintf('/trips/%s/detail', $tripId), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->hasSubscriptionCookie($detail), 'GET /trips/{id}/detail');
        $etag = (string) ($detail->getHeaders(false)['etag'][0] ?? '');

        $patch = $this->client->request('PATCH', \sprintf('/trips/%s', $tripId), [
            'headers' => array_merge(['Content-Type' => 'application/merge-patch+json', 'If-Match' => $etag], $this->authHeader($this->jwtToken)),
            'json' => ['title' => 'Renamed'],
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertTrue($this->hasSubscriptionCookie($patch), 'PATCH /trips/{id}');

        $duplicate = $this->client->request('POST', \sprintf('/trips/%s/duplicate', $tripId), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'mercure-cookie-duplicate-'.bin2hex(random_bytes(4))], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->hasSubscriptionCookie($duplicate), 'POST /trips/{id}/duplicate');

        $create = $this->client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'mercure-cookie-create-'.bin2hex(random_bytes(4))], $this->authHeader($this->jwtToken)),
            'json' => ['sourceUrl' => 'https://www.komoot.com/tour/123456789'],
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertTrue($this->hasSubscriptionCookie($create), 'POST /trips');

        $list = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->hasSubscriptionCookie($list), 'GET /trips names no single trip.');
    }

    /**
     * The share page reuses the detail provider; an anonymous reader is not subscribed to the
     * owner's live updates.
     */
    #[Test]
    public function theAnonymousSharePageIsNotSubscribed(): void
    {
        $upload = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => ['files' => ['gpxFile' => new UploadedFile(__DIR__.'/../fixtures/valid-route.gpx', 'valid-route.gpx', 'application/gpx+xml', null, true)]],
        ]);
        self::assertResponseStatusCodeSame(202);
        $tripId = (string) $upload->toArray(false)['id'];

        $share = $this->client->request('POST', \sprintf('/trips/%s/share', $tripId), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        self::assertResponseStatusCodeSame(201);

        $shared = $this->client->request('GET', \sprintf('/s/%s', (string) $share->toArray(false)['shortCode']), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->hasSubscriptionCookie($shared));
    }

    private function hasSubscriptionCookie(ResponseInterface $response): bool
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            if (str_starts_with($cookie, self::COOKIE.'=')) {
                return true;
            }
        }

        return false;
    }
}
