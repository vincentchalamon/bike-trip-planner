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
 */
final readonly class OsmScanner implements ScannerInterface
{
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        #[Autowire(service: 'overpass.client')]
        private HttpClientInterface $overpassClient,
        #[Autowire(service: 'cache.osm')]
        private CacheInterface $osmCache,
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

                $response = $this->overpassClient->request('POST', '/api/interpreter', [
                    'body' => ['data' => $query],
                ]);

                /* @var array<string, mixed> */
                return $response->toArray();
            });
        } catch (\Throwable $throwable) {
            $this->logger->warning('Overpass query failed, returning empty result.', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }
}
