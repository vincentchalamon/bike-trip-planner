<?php

declare(strict_types=1);

namespace App\Geo;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side reverse geocoding for stage endpoint labels (recette #649, #3c/#9).
 *
 * Resolves a coordinate to a city-preferred place name via Nominatim, cached for
 * 24h. Shares the cache key/shape with {@see \App\Controller\GeocodeController}'s
 * `/geocode/reverse` endpoint, so a lookup from either path warms the other.
 * Used by {@see \App\MessageHandler\ResolveStageLabelsHandler} to persist labels
 * so the anonymous shared view — which cannot call the auth-gated endpoint — and
 * a reloaded trip render city names instead of raw GPS coordinates.
 */
final readonly class ReverseGeocoder
{
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        #[Autowire(service: 'nominatim.client')]
        private HttpClientInterface $nominatimClient,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $osmCache,
    ) {
    }

    /**
     * Returns the city-preferred place name for the coordinate, or null when the
     * service is unavailable or finds nothing. Coordinates are rounded to 4
     * decimals (~11 m) for the cache key, matching the search endpoint.
     */
    public function cityName(float $lat, float $lon): ?string
    {
        $cacheKey = \sprintf('geocode.reverse.%s.%s', round($lat, 4), round($lon, 4));
        $item = $this->osmCache->getItem($cacheKey);

        if ($item->isHit()) {
            /** @var list<array{name: string, lat: float, lon: float, displayName: string, type: string}> $cached */
            $cached = $item->get();
            $name = $cached[0]['name'] ?? '';

            return '' === $name ? null : $name;
        }

        try {
            $response = $this->nominatimClient->request('GET', '/reverse', [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                ],
            ]);

            /** @var array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string, address?: array{city?: string, town?: string, village?: string, hamlet?: string, municipality?: string}, error?: string} $data */
            $data = $response->toArray();
        } catch (\Throwable) {
            return null;
        }

        if (isset($data['error'])) {
            return null;
        }

        // Prefer the city/town/village name over the raw POI name.
        $address = $data['address'] ?? [];
        $name = $data['name'] ?? '';
        $cityName = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['municipality'] ?? null;
        if (null !== $cityName && '' !== $cityName) {
            $name = $cityName;
        }

        // Cache in the same shape as GeocodeController so both paths share entries.
        $item->set([[
            'name' => $name,
            'lat' => (float) ($data['lat'] ?? $lat),
            'lon' => (float) ($data['lon'] ?? $lon),
            'displayName' => $data['display_name'] ?? '',
            'type' => $data['addresstype'] ?? $data['type'] ?? 'place',
        ]]);
        $item->expiresAfter(self::CACHE_TTL);

        $this->osmCache->save($item);

        return '' === $name ? null : $name;
    }
}
