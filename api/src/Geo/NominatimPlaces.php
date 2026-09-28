<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\GeocodeResult;
use App\Service\NominatimThrottle;
use App\State\Mcp\ThirdPartyText;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Place search and reverse geocoding against Nominatim, for the two endpoints that expose them.
 *
 * One class so both answers get the same treatment: the 24-hour cache, the per-user throttle
 * spent only on a cache miss, a 502 when Nominatim fails, and {@see ThirdPartyText::clean()} on
 * the labels OpenStreetMap contributors wrote. The two used to live in a provider and a
 * controller, and only the provider cleaned its labels.
 */
final readonly class NominatimPlaces
{
    private const int CACHE_TTL = 86400;

    public function __construct(
        #[Autowire(service: 'nominatim.client')]
        private HttpClientInterface $nominatim,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $cache,
        private NominatimThrottle $throttle,
    ) {
    }

    /**
     * @return list<GeocodeResult>
     */
    public function search(string $query, int $limit): array
    {
        return $this->cached(\sprintf('geocode.search.%s.%d', md5($query), $limit), function () use ($query, $limit): array {
            /** @var list<array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string}> $data */
            $data = $this->request('/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'limit' => $limit,
                'addressdetails' => 1,
            ]);

            return array_map(
                static fn (array $place): GeocodeResult => new GeocodeResult(
                    name: ThirdPartyText::clean($place['name'] ?? null) ?? '',
                    lat: (float) ($place['lat'] ?? 0),
                    lon: (float) ($place['lon'] ?? 0),
                    displayName: ThirdPartyText::clean($place['display_name'] ?? null) ?? '',
                    type: $place['addresstype'] ?? $place['type'] ?? 'place',
                ),
                $data,
            );
        });
    }

    /**
     * @return list<GeocodeResult> the place at these coordinates, or nothing when Nominatim has none
     */
    public function reverse(float $lat, float $lon): array
    {
        return $this->cached(\sprintf('geocode.reverse.v2.%s.%s', round($lat, 4), round($lon, 4)), function () use ($lat, $lon): array {
            /** @var array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string, address?: array{city?: string, town?: string, village?: string, hamlet?: string, municipality?: string}, error?: string} $data */
            $data = $this->request('/reverse', [
                'lat' => $lat,
                'lon' => $lon,
                'format' => 'jsonv2',
                'addressdetails' => 1,
            ]);

            if (isset($data['error'])) {
                return [];
            }

            // Prefer the city/town/village name over the raw POI name.
            $address = $data['address'] ?? [];
            $name = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['municipality'] ?? null;
            if (null === $name || '' === $name) {
                $name = $data['name'] ?? null;
            }

            return [new GeocodeResult(
                name: ThirdPartyText::clean($name) ?? '',
                lat: (float) ($data['lat'] ?? $lat),
                lon: (float) ($data['lon'] ?? $lon),
                displayName: ThirdPartyText::clean($data['display_name'] ?? null) ?? '',
                type: $data['addresstype'] ?? $data['type'] ?? 'place',
            )];
        });
    }

    /**
     * @param callable(): list<GeocodeResult> $fetch
     *
     * @return list<GeocodeResult>
     */
    private function cached(string $key, callable $fetch): array
    {
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            /** @var list<GeocodeResult> $cached */
            $cached = $item->get();

            return $cached;
        }

        // After the cache on purpose: a repeated lookup costs the third party nothing, so it
        // should not cost the caller a token either.
        $this->throttle->throttle();

        $results = $fetch();

        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);

        $this->cache->save($item);

        return $results;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function request(string $path, array $query): array
    {
        try {
            return $this->nominatim->request('GET', $path, ['query' => $query])->toArray();
        } catch (\Throwable) {
            // The failure is upstream, not in the request.
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'Geocoding service unavailable');
        }
    }
}
