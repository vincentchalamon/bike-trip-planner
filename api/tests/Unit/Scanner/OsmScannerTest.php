<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\OsmScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class OsmScannerTest extends TestCase
{
    private const string QUERY_A = '[out:json];node["amenity"="cafe"];out;';

    private const string QUERY_B = '[out:json];node["shop"="bicycle"];out;';

    private const string QUERY_C = '[out:json];way["highway"];out;';

    #[Test]
    public function queryBatchAllCachedMakesZeroHttpCalls(): void
    {
        $httpCallCount = 0;
        $client = new MockHttpClient(function () use (&$httpCallCount): MockResponse {
            ++$httpCallCount;

            return new MockResponse('{}');
        }, 'https://overpass-api.de');

        $resultA = ['elements' => [['id' => 1]]];
        $resultB = ['elements' => [['id' => 2]]];

        $cachePool = $this->createCachePool([
            $this->cacheKey(self::QUERY_A) => $resultA,
            $this->cacheKey(self::QUERY_B) => $resultB,
        ]);

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $cachePool,
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
            'b' => self::QUERY_B,
        ]);

        $this->assertSame($resultA, $results['a']);
        $this->assertSame($resultB, $results['b']);
        $this->assertSame(0, $httpCallCount);
    }

    #[Test]
    public function queryBatchAllUncachedSucceeds(): void
    {
        $callCount = 0;

        $client = new MockHttpClient(function () use (&$callCount): MockResponse {
            ++$callCount;

            return new MockResponse(json_encode(['elements' => [['id' => $callCount]]], \JSON_THROW_ON_ERROR));
        }, 'https://overpass-api.de');

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
            'b' => self::QUERY_B,
            'c' => self::QUERY_C,
        ]);

        $this->assertCount(3, $results);
        $this->assertSame(3, $callCount);
    }

    #[Test]
    public function queryFailureReturnsEmptyGracefully(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse('Server Error', ['http_code' => 500]), 'https://overpass-api.de');

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            new NullLogger(),
        );

        $result = $scanner->query(self::QUERY_A);

        $this->assertSame([], $result);
    }

    #[Test]
    public function queryBatchFailureReturnsEmptyGracefully(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse('Server Error', ['http_code' => 500]), 'https://overpass-api.de');

        $storedKeys = [];
        $cachePool = $this->createEmptyCachePool($storedKeys);

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $cachePool,
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
        ]);

        // Failed query returns empty array (graceful degradation)
        $this->assertArrayHasKey('a', $results);
        $this->assertSame([], $results['a']);
        // Transient HTTP failure must NOT be written to the cache
        $this->assertEmpty($storedKeys, 'A transient HTTP failure must not be written to the cache.');
    }

    #[Test]
    public function queryBatchMixedCacheHitAndMissUsesHttpOnlyForMisses(): void
    {
        $httpCallCount = 0;
        $client = new MockHttpClient(function () use (&$httpCallCount): MockResponse {
            ++$httpCallCount;

            return new MockResponse(json_encode(['elements' => [['id' => 99]]], \JSON_THROW_ON_ERROR));
        }, 'https://overpass-api.de');

        $cachedResult = ['elements' => [['id' => 1]]];
        $cachePool = $this->createCachePool([
            $this->cacheKey(self::QUERY_A) => $cachedResult,
        ]);

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $cachePool,
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A, // cache hit
            'b' => self::QUERY_B, // cache miss → HTTP
        ]);

        $this->assertSame($cachedResult, $results['a']);
        $this->assertSame([['id' => 99]], $results['b']['elements']);
        $this->assertSame(1, $httpCallCount); // only one HTTP call for the miss
    }

    #[Test]
    public function cacheKeyAlignmentBetweenQueryAndQueryBatch(): void
    {
        $query = self::QUERY_A;
        $expectedResult = ['elements' => [['id' => 42]]];

        // Track what cache keys are stored by queryBatch
        $storedKeys = [];
        $cachePool = $this->createEmptyCachePool($storedKeys);

        $client = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode($expectedResult, \JSON_THROW_ON_ERROR)), 'https://overpass-api.de');

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $cachePool,
            new NullLogger(),
        );

        $scanner->queryBatch(['test' => $query]);

        // The cache key used by queryBatch must match what query() would use
        $expectedKey = 'osm.'.hash('xxh128', $query);
        $this->assertContains($expectedKey, $storedKeys);
    }

    #[Test]
    public function queryBatchGenuineEmptyResultIsCached(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode(['elements' => []], \JSON_THROW_ON_ERROR)), 'https://overpass-api.de');

        $storedKeys = [];
        $cachePool = $this->createEmptyCachePool($storedKeys);

        $scanner = new OsmScanner(
            $client,
            $this->createPassthroughCache(),
            $cachePool,
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
        ]);

        $this->assertArrayHasKey('a', $results);
        $this->assertSame([], $results['a']);
        // Genuine empty result (no OSM data) MUST be cached to avoid redundant API calls
        $expectedKey = 'osm.'.hash('xxh128', self::QUERY_A);
        $this->assertContains($expectedKey, $storedKeys, 'A genuine empty result must be written to the cache.');
    }

    private function cacheKey(string $query): string
    {
        return 'osm.'.hash('xxh128', $query);
    }

    private function createPassthroughCache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback): mixed {
                $item = $this->createStub(ItemInterface::class);

                return $callback($item);
            });

        return $cache;
    }

    /**
     * Creates a PSR-6 cache pool pre-populated with the given entries.
     *
     * @param array<string, array<string, mixed>> $entries Map of cache key => value
     */
    private function createCachePool(array $entries = []): CacheItemPoolInterface
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);

        $createItem = function (string $key) use ($entries): CacheItemInterface {
            $item = $this->createStub(CacheItemInterface::class);
            $item->method('getKey')->willReturn($key);

            if (isset($entries[$key])) {
                $item->method('isHit')->willReturn(true);
                $item->method('get')->willReturn($entries[$key]);
            } else {
                $item->method('isHit')->willReturn(false);
                $item->method('get')->willReturn(null);
                $item->method('set')->willReturnSelf();
                $item->method('expiresAfter')->willReturnSelf();
            }

            return $item;
        };

        $pool->method('getItem')->willReturnCallback($createItem);
        $pool->method('getItems')
            ->willReturnCallback(function (array $keys) use ($createItem): iterable {
                $items = [];
                foreach ($keys as $key) {
                    $items[$key] = $createItem($key);
                }

                return $items;
            });
        $pool->method('save')->willReturn(true);

        return $pool;
    }

    /**
     * Creates an empty PSR-6 cache pool that tracks stored keys.
     *
     * @param list<string> $storedKeys Reference to collect stored cache keys
     */
    private function createEmptyCachePool(array &$storedKeys = []): CacheItemPoolInterface
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);

        $createItem = function (string $key): CacheItemInterface {
            $item = $this->createStub(CacheItemInterface::class);
            $item->method('isHit')->willReturn(false);
            $item->method('get')->willReturn(null);
            $item->method('getKey')->willReturn($key);
            $item->method('set')->willReturnSelf();
            $item->method('expiresAfter')->willReturnSelf();

            return $item;
        };

        $pool->method('getItem')->willReturnCallback($createItem);
        $pool->method('getItems')
            ->willReturnCallback(function (array $keys) use ($createItem): iterable {
                $items = [];
                foreach ($keys as $key) {
                    $items[$key] = $createItem($key);
                }

                return $items;
            });
        $pool->method('save')
            ->willReturnCallback(function (CacheItemInterface $item) use (&$storedKeys): bool {
                $storedKeys[] = $item->getKey();

                return true;
            });

        return $pool;
    }
}
