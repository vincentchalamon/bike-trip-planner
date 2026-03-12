<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\OsmScanner;
use App\Scanner\OverpassStatusCheckerInterface;
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
        $localClient = new MockHttpClient(function () use (&$httpCallCount): MockResponse {
            ++$httpCallCount;

            return new MockResponse('{}');
        }, 'http://overpass-local');

        $publicClient = new MockHttpClient(function () use (&$httpCallCount): MockResponse {
            ++$httpCallCount;

            return new MockResponse('{}');
        }, 'http://overpass-public');

        $resultA = ['elements' => [['id' => 1]]];
        $resultB = ['elements' => [['id' => 2]]];

        $cachePool = $this->createCachePool([
            $this->cacheKey(self::QUERY_A) => $resultA,
            $this->cacheKey(self::QUERY_B) => $resultB,
        ]);

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $cachePool,
            $this->createStatusChecker(true),
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
    public function queryBatchAllUncachedLocalSucceedsNoPublicCalls(): void
    {
        $localCallCount = 0;
        $publicCallCount = 0;

        $localClient = new MockHttpClient(function () use (&$localCallCount): MockResponse {
            ++$localCallCount;

            return new MockResponse(json_encode(['elements' => [['id' => $localCallCount]]], \JSON_THROW_ON_ERROR));
        }, 'http://overpass-local');

        $publicClient = new MockHttpClient(function () use (&$publicCallCount): MockResponse {
            ++$publicCallCount;

            return new MockResponse('{}');
        }, 'http://overpass-public');

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            $this->createStatusChecker(true),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
            'b' => self::QUERY_B,
            'c' => self::QUERY_C,
        ]);

        $this->assertCount(3, $results);
        $this->assertSame(3, $localCallCount);
        $this->assertSame(0, $publicCallCount);
    }

    #[Test]
    public function queryBatchLocalUnavailableAllGoToPublic(): void
    {
        $localCallCount = 0;
        $publicCallCount = 0;

        $localClient = new MockHttpClient(function () use (&$localCallCount): MockResponse {
            ++$localCallCount;

            return new MockResponse('{}');
        }, 'http://overpass-local');

        $publicClient = new MockHttpClient(function () use (&$publicCallCount): MockResponse {
            ++$publicCallCount;

            return new MockResponse(json_encode(['elements' => [['id' => $publicCallCount]]], \JSON_THROW_ON_ERROR));
        }, 'http://overpass-public');

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            $this->createStatusChecker(false),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
            'b' => self::QUERY_B,
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(0, $localCallCount);
        $this->assertSame(2, $publicCallCount);
    }

    #[Test]
    public function queryBatchLocalEmptyForSomeFallsBackToPublic(): void
    {
        $localRequestIndex = 0;
        $localClient = new MockHttpClient(function () use (&$localRequestIndex): MockResponse {
            ++$localRequestIndex;

            // First query returns results, second returns empty
            if (1 === $localRequestIndex) {
                return new MockResponse(json_encode(['elements' => [['id' => 1]]], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['elements' => []], \JSON_THROW_ON_ERROR));
        }, 'http://overpass-local');

        $publicCallCount = 0;
        $publicClient = new MockHttpClient(function () use (&$publicCallCount): MockResponse {
            ++$publicCallCount;

            return new MockResponse(json_encode(['elements' => [['id' => 99]]], \JSON_THROW_ON_ERROR));
        }, 'http://overpass-public');

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            $this->createStatusChecker(true),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
            'b' => self::QUERY_B,
        ]);

        $this->assertCount(2, $results);
        $this->assertSame([['id' => 1]], $results['a']['elements']);
        $this->assertSame([['id' => 99]], $results['b']['elements']);
        $this->assertSame(1, $publicCallCount);
    }

    #[Test]
    public function queryBatchPublicFailureReturnsEmptyGracefully(): void
    {
        $localClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode(['elements' => []], \JSON_THROW_ON_ERROR)), 'http://overpass-local');

        $publicClient = new MockHttpClient(fn (): MockResponse => new MockResponse('Server Error', ['http_code' => 500]), 'http://overpass-public');

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $this->createEmptyCachePool(),
            $this->createStatusChecker(true),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A,
        ]);

        // Failed query returns empty array (consistent with query() graceful degradation)
        $this->assertArrayHasKey('a', $results);
        $this->assertSame([], $results['a']);
    }

    #[Test]
    public function queryBatchMixedCacheHitAndMissUsesHttpOnlyForMisses(): void
    {
        $httpCallCount = 0;
        $localClient = new MockHttpClient(function () use (&$httpCallCount): MockResponse {
            ++$httpCallCount;

            return new MockResponse(json_encode(['elements' => [['id' => 99]]], \JSON_THROW_ON_ERROR));
        }, 'http://overpass-local');

        $publicClient = new MockHttpClient([], 'http://overpass-public');

        $cachedResult = ['elements' => [['id' => 1]]];
        $cachePool = $this->createCachePool([
            $this->cacheKey(self::QUERY_A) => $cachedResult,
        ]);

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $cachePool,
            $this->createStatusChecker(true),
            new NullLogger(),
        );

        $results = $scanner->queryBatch([
            'a' => self::QUERY_A, // cache hit
            'b' => self::QUERY_B, // cache miss → local HTTP
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

        $localClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode($expectedResult, \JSON_THROW_ON_ERROR)), 'http://overpass-local');

        $publicClient = new MockHttpClient([], 'http://overpass-public');

        $scanner = new OsmScanner(
            $localClient,
            $publicClient,
            $this->createPassthroughCache(),
            $cachePool,
            $this->createStatusChecker(true),
            new NullLogger(),
        );

        $scanner->queryBatch(['test' => $query]);

        // The cache key used by queryBatch must match what query() would use
        $expectedKey = 'osm.'.hash('xxh128', $query);
        $this->assertContains($expectedKey, $storedKeys);
    }

    private function cacheKey(string $query): string
    {
        return 'osm.'.hash('xxh128', $query);
    }

    private function createStatusChecker(bool $ready): OverpassStatusCheckerInterface
    {
        $checker = $this->createStub(OverpassStatusCheckerInterface::class);
        $checker->method('isReady')->willReturn($ready);

        return $checker;
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
