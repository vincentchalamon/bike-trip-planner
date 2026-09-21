<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use App\ApiResource\Model\Coordinate;
use App\Enum\SourceType;
use App\RouteParser\GpxRouteParserInterface;
use App\RouteFetcher\StravaRouteFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class StravaRouteFetcherTest extends TestCase
{
    #[Test]
    public function supportsStravaRouteUrl(): void
    {
        $fetcher = $this->createFetcher();

        self::assertTrue($fetcher->supports('https://www.strava.com/routes/123456'));
        self::assertTrue($fetcher->supports('https://www.strava.com/routes/99999999'));
    }

    #[Test]
    public function doesNotSupportOtherUrls(): void
    {
        $fetcher = $this->createFetcher();

        self::assertFalse($fetcher->supports('https://www.komoot.com/tour/123'));
        self::assertFalse($fetcher->supports('https://www.strava.com/activities/123'));
        self::assertFalse($fetcher->supports('https://ridewithgps.com/routes/123'));
        self::assertFalse($fetcher->supports('https://example.com'));
    }

    #[Test]
    public function fetchReturnsRouteFetchResult(): void
    {
        $gpxContent = '<gpx><trk><name>My Strava Route</name></trk></gpx>';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($gpxContent);

        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with('GET', '/routes/123456/export_gpx')
            ->willReturn($response);

        $gpxParser = $this->createMock(GpxRouteParserInterface::class);
        $gpxParser->expects(self::once())
            ->method('parse')
            ->with($gpxContent)
            ->willReturn([new Coordinate(44.0, 5.0, 100.0)]);
        $gpxParser->expects(self::once())
            ->method('extractTitle')
            ->with($gpxContent)
            ->willReturn('My Strava Route');

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new StravaRouteFetcher($client, $gpxParser, $cache);
        $result = $fetcher->fetch('https://www.strava.com/routes/123456');

        self::assertSame(SourceType::STRAVA_ROUTE, $result->sourceType);
        self::assertSame('My Strava Route', $result->title);
        self::assertCount(1, $result->tracks);
        self::assertCount(1, $result->tracks[0]);
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

        $fetcher = new StravaRouteFetcher(
            $client,
            $this->createStub(GpxRouteParserInterface::class),
            $cache,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found (404)');

        $fetcher->fetch('https://www.strava.com/routes/123456');
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

        $fetcher = new StravaRouteFetcher(
            $client,
            $this->createStub(GpxRouteParserInterface::class),
            $cache,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or access denied (403)');

        $fetcher->fetch('https://www.strava.com/routes/123456');
    }

    #[Test]
    public function fetchThrowsOnEmptyCoordinates(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('<gpx/>');

        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $gpxParser = $this->createStub(GpxRouteParserInterface::class);
        $gpxParser->method('parse')->willReturn([]);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class)),
        );

        $fetcher = new StravaRouteFetcher($client, $gpxParser, $cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no valid coordinates');

        $fetcher->fetch('https://www.strava.com/routes/123456');
    }

    private function createFetcher(): StravaRouteFetcher
    {
        return new StravaRouteFetcher(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(GpxRouteParserInterface::class),
            $this->createStub(CacheInterface::class),
        );
    }
}
