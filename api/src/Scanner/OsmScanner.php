<?php

declare(strict_types=1);

namespace App\Scanner;

use Throwable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenStreetMap scanner combined with {@see OsmOverpassQueryBuilder}.
 *
 * Queries the local Overpass instance first (if ready), falling back
 * to the public overpass-api.de when local is unavailable or returns
 * empty results (route outside imported region).
 */
final readonly class OsmScanner implements ScannerInterface
{
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        #[Autowire(service: 'overpass.local.client')]
        private HttpClientInterface $localClient,
        #[Autowire(service: 'overpass.public.client')]
        private HttpClientInterface $publicClient,
        #[Autowire(service: 'cache.osm')]
        private CacheInterface $osmCache,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $osmCachePool,
        private OverpassStatusCheckerInterface $statusChecker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Executes an Overpass QL query and returns the parsed JSON result.
     * Results are cached for 24h. Gracefully degrades on error (returns empty array).
     *
     * @return array<string, mixed>
     */
    public function query(string $query): array
    {
        $cacheKey = $this->cacheKey($query);

        try {
            /* @var array<string, mixed> */
            return $this->osmCache->get($cacheKey, function (ItemInterface $item) use ($query): array {
                $item->expiresAfter(self::CACHE_TTL);

                if ($this->statusChecker->isReady()) {
                    $result = $this->executeQuery($this->localClient, $query);

                    if ($this->hasResults($result)) {
                        return $result;
                    }

                    $this->logger->info('Local Overpass returned empty results, falling back to public API.');
                }

                return $this->executeQuery($this->publicClient, $query);
            });
        } catch (Throwable $throwable) {
            $this->logger->warning('Overpass query failed, returning empty result.', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Executes multiple Overpass QL queries concurrently with cache lookup and two-wave
     * fallback (local -> public).
     *
     * Phase 1: Check cache for all queries via PSR-6.
     * Phase 2 (Wave 1): Fire all uncached queries on the local client concurrently.
     * Phase 3 (Wave 2): Fire queries that returned empty/failed results on the public client.
     *
     * @param array<string, string> $queries Map of logical name => Overpass QL query
     *
     * @return array<string, array<string, mixed>> Map of logical name => parsed JSON result
     */
    public function queryBatch(array $queries): array
    {
        /** @var array<string, array<string, mixed>> $results */
        $results = [];

        // Phase 1: Check cache for all queries
        /** @var array<string, string> $uncached Map of logical name => query for uncached entries */
        $uncached = [];

        /** @var array<string, string> $keyToName */
        $keyToName = [];
        /** @var array<string, string> $keyToQuery */
        $keyToQuery = [];

        foreach ($queries as $name => $query) {
            $key = $this->cacheKey($query);
            $keyToName[$key] = $name;
            $keyToQuery[$key] = $query;
        }

        foreach ($this->osmCachePool->getItems(array_keys($keyToName)) as $key => $cacheItem) {
            $name = $keyToName[$key];

            if ($cacheItem->isHit()) {
                /** @var array<string, mixed> $cached */
                $cached = $cacheItem->get();
                $results[$name] = $cached;
            } else {
                $uncached[$name] = $keyToQuery[$key];
            }
        }

        if ([] === $uncached) {
            return $results;
        }

        if ($this->statusChecker->isReady()) {
            [$localEmpties, $localFailures] = $this->executeWave($this->localClient, $uncached, $results);
            $needsPublic = array_merge($localEmpties, $localFailures);
        } else {
            $needsPublic = $uncached;
        }

        if ([] === $needsPublic) {
            return $results;
        }

        // Phase 3 (Wave 2): Fire remaining queries on public client concurrently
        [$publicEmpties, $publicFailures] = $this->executeWave($this->publicClient, $needsPublic, $results);

        // Genuine empty results (no OSM data in this area): cache [] to honour the 24 h TTL contract.
        foreach ($publicEmpties as $name => $query) {
            $results[$name] = [];
            $this->cacheResult($query, []);
        }

        // Transient HTTP failures: return [] gracefully but do NOT cache — the error may be temporary.
        foreach ($publicFailures as $name => $query) {
            $results[$name] = [];
        }

        return $results;
    }

    /**
     * Fires a batch of queries concurrently on a given HTTP client, collecting results.
     *
     * Returns two arrays to allow the caller to distinguish the outcomes:
     * - empties: queries that returned a successful HTTP response with no elements (genuine absence of OSM data)
     * - failures: queries that threw an exception (transient HTTP error, timeout, etc.)
     *
     * Only entries in "empties" are safe to cache long-term; "failures" must not be cached.
     *
     * @param array<string, string>               $queries Map of logical name => query
     * @param array<string, array<string, mixed>> $results Collected results (modified by reference)
     *
     * @return array{array<string, string>, array<string, string>} [empties, failures]
     */
    private function executeWave(HttpClientInterface $client, array $queries, array &$results): array
    {
        /** @var array<string, ResponseInterface> $responses */
        $responses = [];

        foreach ($queries as $name => $query) {
            $responses[$name] = $client->request('POST', '/api/interpreter', [
                'body' => ['data' => $query],
            ]);
        }

        /** @var array<string, string> $empties */
        $empties = [];
        /** @var array<string, string> $failures */
        $failures = [];

        foreach ($responses as $name => $response) {
            try {
                /** @var array<string, mixed> $result */
                $result = $response->toArray();

                if ($this->hasResults($result)) {
                    $results[$name] = $result;
                    $this->cacheResult($queries[$name], $result);
                } else {
                    $this->logger->info('Overpass returned empty results for "{name}", falling back.', [
                        'name' => $name,
                    ]);
                    $empties[$name] = $queries[$name];
                }
            } catch (Throwable $throwable) {
                $this->logger->warning('Overpass query "{name}" failed, falling back.', [
                    'name' => $name,
                    'error' => $throwable->getMessage(),
                ]);
                $failures[$name] = $queries[$name];
            }
        }

        return [$empties, $failures];
    }

    /**
     * Caches a successful query result via PSR-6.
     *
     * @param array<string, mixed> $result
     */
    private function cacheResult(string $query, array $result): void
    {
        $cacheItem = $this->osmCachePool->getItem($this->cacheKey($query));
        $cacheItem->set($result);
        $cacheItem->expiresAfter(self::CACHE_TTL);

        $this->osmCachePool->save($cacheItem);
    }

    /**
     * Computes the cache key for a given Overpass QL query.
     * Shared between query() and queryBatch() to ensure cache alignment.
     */
    private function cacheKey(string $query): string
    {
        return 'osm.'.hash('xxh128', $query);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeQuery(HttpClientInterface $client, string $query): array
    {
        $response = $client->request('POST', '/api/interpreter', [
            'body' => ['data' => $query],
        ]);

        /* @var array<string, mixed> */
        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hasResults(array $result): bool
    {
        /** @var list<mixed> $elements */
        $elements = $result['elements'] ?? [];

        return [] !== $elements;
    }
}
