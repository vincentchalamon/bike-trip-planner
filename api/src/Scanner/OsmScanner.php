<?php

declare(strict_types=1);

namespace App\Scanner;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private LocalOverpassStatusChecker $statusChecker,
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
        $cacheKey = 'osm.'.hash('xxh128', $query);

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
        } catch (\Throwable $throwable) {
            $this->logger->warning('Overpass query failed, returning empty result.', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
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
