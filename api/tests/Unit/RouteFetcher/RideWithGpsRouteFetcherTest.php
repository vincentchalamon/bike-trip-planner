<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use App\Enum\SourceType;
use App\RouteFetcher\RideWithGpsRouteFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RideWithGpsRouteFetcherTest extends TestCase
{
    #[Test]
    public function supportsRideWithGpsUrl(): void
    {
        $fetcher = $this->createFetcher();

        self::assertTrue($fetcher->supports('https://ridewithgps.com/routes/123456'));
        self::assertTrue($fetcher->supports('https://ridewithgps.com/routes/99999999'));
    }

    #[Test]
    public function doesNotSupportOtherUrls(): void
    {
        $fetcher = $this->createFetcher();

        self::assertFalse($fetcher->supports('https://www.komoot.com/tour/123'));
        self::assertFalse($fetcher->supports('https://www.strava.com/routes/123'));
        self::assertFalse($fetcher->supports('https://ridewithgps.com/trips/123'));
        self::assertFalse($fetcher->supports('https://example.com'));
    }

    #[Test]
    public function fetchReturnsRouteFetchResult(): void
    {
        $jsonData = [
            'route' => [
                'name' => 'My RWGPS Route',
                'track_points' => [
                    ['lat' => 44.0, 'lng' => 5.0, 'e' => 100.0],
                    ['lat' => 44.1, 'lng' => 5.1, 'e' => 150.0],
                    ['lat' => 44.2, 'lng' => 5.2, 'e' => 200.0],
                ],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($jsonData);

        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with('GET', '/routes/123456.json')
            ->willReturn($response);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new RideWithGpsRouteFetcher($client, $cache);
        $result = $fetcher->fetch('https://ridewithgps.com/routes/123456');

        self::assertSame(SourceType::RIDE_WITH_GPS, $result->sourceType);
        self::assertSame('My RWGPS Route', $result->title);
        self::assertCount(1, $result->tracks);
        self::assertCount(3, $result->tracks[0]);
        self::assertSame(44.0, $result->tracks[0][0]->lat);
        self::assertSame(5.0, $result->tracks[0][0]->lon);
        self::assertSame(100.0, $result->tracks[0][0]->ele);
    }

    #[Test]
    public function fetchThrowsOn404(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new RideWithGpsRouteFetcher($client, $cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found (404)');

        $fetcher->fetch('https://ridewithgps.com/routes/123456');
    }

    #[Test]
    public function fetchThrowsOn403(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new RideWithGpsRouteFetcher($client, $cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or access denied (403)');

        $fetcher->fetch('https://ridewithgps.com/routes/123456');
    }

    #[Test]
    public function fetchThrowsOnEmptyTrackPoints(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'route' => [
                'name' => 'Empty Route',
                'track_points' => [],
            ],
        ]);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new RideWithGpsRouteFetcher($client, $cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no track points');

        $fetcher->fetch('https://ridewithgps.com/routes/123456');
    }

    #[Test]
    public function fetchSkipsInvalidCoordinates(): void
    {
        $jsonData = [
            'route' => [
                'name' => 'Mixed Route',
                'track_points' => [
                    ['lat' => 44.0, 'lng' => 5.0, 'e' => 100.0],
                    ['lat' => 'invalid', 'lng' => 5.1, 'e' => 150.0],
                    ['lat' => 44.2, 'lng' => 5.2, 'e' => 200.0],
                ],
            ],
        ];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($jsonData);

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new RideWithGpsRouteFetcher($client, $cache);
        $result = $fetcher->fetch('https://ridewithgps.com/routes/123456');

        self::assertCount(1, $result->tracks);
        self::assertCount(2, $result->tracks[0]);
    }

    private function createFetcher(): RideWithGpsRouteFetcher
    {
        return new RideWithGpsRouteFetcher(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(CacheInterface::class),
        );
    }
}
