<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataTourisme;

use App\DataTourisme\DataTourismeClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DataTourismeClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isEnabled()
    // -------------------------------------------------------------------------

    #[Test]
    public function isEnabledReturnsTrueWhenFlagAndKeyAreSet(): void
    {
        $client = $this->makeClient(apiKey: 'secret', enabled: true);

        $this->assertTrue($client->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenFlagIsOff(): void
    {
        $client = $this->makeClient(apiKey: 'secret', enabled: false);

        $this->assertFalse($client->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenKeyIsEmpty(): void
    {
        $client = $this->makeClient(apiKey: '', enabled: true);

        $this->assertFalse($client->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseWhenBothFlagAndKeyAreAbsent(): void
    {
        $client = $this->makeClient(apiKey: '', enabled: false);

        $this->assertFalse($client->isEnabled());
    }

    // -------------------------------------------------------------------------
    // request() — cache hit
    // -------------------------------------------------------------------------

    #[Test]
    public function requestReturnsCachedResultWithoutHttpCall(): void
    {
        $cached = ['results' => [['id' => 'poi-1']]];

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cached);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $rateLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiter->expects($this->never())->method('create');

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, rateLimiter: $rateLimiter);

        $result = $client->request('/api/v1/places', ['type' => 'museum']);

        $this->assertSame($cached, $result);
    }

    // -------------------------------------------------------------------------
    // request() — cache miss → HTTP call
    // -------------------------------------------------------------------------

    #[Test]
    public function requestFetchesAndCachesOnCacheMiss(): void
    {
        $apiResponse = ['results' => [['id' => 'poi-2']]];

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())->method('expiresAfter')->with(86400);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $rateLimiter = $this->createStub(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($limiter);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn($apiResponse);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/api/v1/places', ['query' => ['type' => 'museum']])
            ->willReturn($response);

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, rateLimiter: $rateLimiter);

        $result = $client->request('/api/v1/places', ['type' => 'museum']);

        $this->assertSame($apiResponse, $result);
    }

    #[Test]
    public function requestUsesTtlFromArgumentOnCacheMiss(): void
    {
        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())->method('expiresAfter')->with(3600);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $rateLimiter = $this->createStub(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($limiter);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([]);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, rateLimiter: $rateLimiter);

        $client->request('/api/v1/places', [], 3600);
    }

    // -------------------------------------------------------------------------
    // request() — rate limit exhausted
    // -------------------------------------------------------------------------

    #[Test]
    public function requestReturnsEmptyAndLogsWarningWhenRateLimitExhausted(): void
    {
        $item = $this->createStub(ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(false);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $rateLimiter = $this->createStub(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($limiter);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('rate limit'));

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, rateLimiter: $rateLimiter, logger: $logger);

        $result = $client->request('/api/v1/places');

        $this->assertSame(['results' => []], $result);
    }

    // -------------------------------------------------------------------------
    // request() — HTTP 5xx / network error
    // -------------------------------------------------------------------------

    #[Test]
    public function requestReturnsEmptyAndLogsWarningOnHttpError(): void
    {
        $item = $this->createStub(ItemInterface::class);
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $rateLimiter = $this->createStub(RateLimiterFactoryInterface::class);
        $rateLimiter->method('create')->willReturn($limiter);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('failed'));

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, rateLimiter: $rateLimiter, logger: $logger);

        $result = $client->request('/api/v1/places');

        $this->assertSame(['results' => []], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(
        ?CacheInterface $cache = null,
        ?HttpClientInterface $httpClient = null,
        ?RateLimiterFactoryInterface $rateLimiter = null,
        ?LoggerInterface $logger = null,
        string $apiKey = 'test-api-key',
        bool $enabled = true,
    ): DataTourismeClient {
        return new DataTourismeClient(
            httpClient: $httpClient ?? $this->createStub(HttpClientInterface::class),
            cache: $cache ?? $this->createStub(CacheInterface::class),
            rateLimiter: $rateLimiter ?? $this->createStub(RateLimiterFactoryInterface::class),
            logger: $logger ?? $this->createStub(LoggerInterface::class),
            apiKey: $apiKey,
            enabled: $enabled,
        );
    }
}
