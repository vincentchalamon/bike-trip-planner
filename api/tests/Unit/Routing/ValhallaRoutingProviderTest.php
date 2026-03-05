<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\ApiResource\Model\Coordinate;
use App\Routing\RoutingUnavailableException;
use App\Routing\ValhallaRoutingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ValhallaRoutingProviderTest extends TestCase
{
    #[Test]
    public function calculateRouteSuccess(): void
    {
        $responseBody = json_encode([
            'trip' => [
                'legs' => [
                    ['shape' => '_izlhA_yrwGAA'],
                ],
                'summary' => [
                    'length' => 12.5,
                    'elevation_gain' => 150.0,
                    'time' => 3600.0,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse, 'http://valhalla:8002');

        $cache = $this->createPassthroughCache();

        $provider = new ValhallaRoutingProvider($httpClient, $cache);
        $result = $provider->calculateRoute(
            new Coordinate(50.0, 2.0),
            new Coordinate(50.1, 2.1),
        );

        $this->assertNotEmpty($result->coordinates);
        $this->assertSame(12500.0, $result->distance);
        $this->assertSame(150.0, $result->elevationGain);
        $this->assertSame(3600.0, $result->duration);
    }

    #[Test]
    public function calculateRouteOutOfRegion170(): void
    {
        $responseBody = json_encode([
            'error_code' => 170,
            'error' => 'No suitable edges near location',
        ], \JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 400]);
        $httpClient = new MockHttpClient($mockResponse, 'http://valhalla:8002');
        $cache = $this->createPassthroughCache();

        $provider = new ValhallaRoutingProvider($httpClient, $cache);

        $this->expectException(RoutingUnavailableException::class);

        $provider->calculateRoute(
            new Coordinate(0.0, 0.0),
            new Coordinate(0.1, 0.1),
        );
    }

    #[Test]
    public function calculateRouteOutOfRegion171(): void
    {
        $responseBody = json_encode([
            'error_code' => 171,
            'error' => 'No route found',
        ], \JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 400]);
        $httpClient = new MockHttpClient($mockResponse, 'http://valhalla:8002');
        $cache = $this->createPassthroughCache();

        $provider = new ValhallaRoutingProvider($httpClient, $cache);

        $this->expectException(RoutingUnavailableException::class);

        $provider->calculateRoute(
            new Coordinate(0.0, 0.0),
            new Coordinate(0.1, 0.1),
        );
    }

    #[Test]
    public function decodePolyline6(): void
    {
        // Two points encoded at polyline6 precision (1e-6):
        // (38.5, -120.2) and (40.7, -120.95)
        $responseBody = json_encode([
            'trip' => [
                'legs' => [
                    ['shape' => '_izlhA~rlgdF_{geC~ywl@'],
                ],
                'summary' => [
                    'length' => 1.0,
                    'elevation_gain' => 0.0,
                    'time' => 60.0,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse, 'http://valhalla:8002');
        $cache = $this->createPassthroughCache();

        $provider = new ValhallaRoutingProvider($httpClient, $cache);
        $result = $provider->calculateRoute(
            new Coordinate(38.5, -120.2),
            new Coordinate(40.7, -120.95),
        );

        $this->assertCount(2, $result->coordinates);
        $this->assertEqualsWithDelta(38.5, $result->coordinates[0]->lat, 0.01);
        $this->assertEqualsWithDelta(-120.2, $result->coordinates[0]->lon, 0.01);
        $this->assertEqualsWithDelta(40.7, $result->coordinates[1]->lat, 0.01);
        $this->assertEqualsWithDelta(-120.95, $result->coordinates[1]->lon, 0.01);
    }

    #[Test]
    public function resultIsCached(): void
    {
        $responseBody = json_encode([
            'trip' => [
                'legs' => [
                    ['shape' => '_izlhA_yrwGAA'],
                ],
                'summary' => [
                    'length' => 10.0,
                    'elevation_gain' => 100.0,
                    'time' => 1800.0,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $callCount = 0;
        $httpClient = new MockHttpClient(function () use ($responseBody, &$callCount): MockResponse {
            ++$callCount;

            return new MockResponse($responseBody, ['http_code' => 200]);
        }, 'http://valhalla:8002');

        // Real caching: first call executes callback, second returns cached value
        $cacheStore = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$cacheStore) {
                if (!isset($cacheStore[$key])) {
                    $item = $this->createStub(ItemInterface::class);
                    $cacheStore[$key] = $callback($item);
                }

                return $cacheStore[$key];
            });

        $provider = new ValhallaRoutingProvider($httpClient, $cache);
        $from = new Coordinate(50.0, 2.0);
        $to = new Coordinate(50.1, 2.1);

        $provider->calculateRoute($from, $to);
        $provider->calculateRoute($from, $to);

        $this->assertSame(1, $callCount);
    }

    private function createPassthroughCache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createStub(ItemInterface::class);

                return $callback($item);
            });

        return $cache;
    }
}
