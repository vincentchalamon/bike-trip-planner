<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Model\Coordinate;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nominatim-backed forward geocoder (B1, ADR-042) used by AI route generation to
 * turn the model's named waypoints into coordinates. Biased to France + Benelux
 * (the coverage area) and cached 24h to respect the Nominatim usage policy.
 */
final readonly class Geocoder implements GeocoderInterface
{
    private const int CACHE_TTL = 86400;

    public function __construct(
        #[Autowire(service: 'nominatim.client')]
        private HttpClientInterface $nominatimClient,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $osmCache,
    ) {
    }

    public function geocode(string $place): ?Coordinate
    {
        $place = trim($place);
        if ('' === $place) {
            return null;
        }

        $item = $this->osmCache->getItem('geocode.fwd.'.md5($place));
        if ($item->isHit()) {
            /** @var array{lat: float, lon: float}|null $cached */
            $cached = $item->get();

            return null === $cached ? null : new Coordinate($cached['lat'], $cached['lon']);
        }

        try {
            $coordinate = $this->query($place);
        } catch (\Throwable) {
            return null; // transient network error - do not cache
        }

        $item->set($coordinate instanceof Coordinate ? ['lat' => $coordinate->lat, 'lon' => $coordinate->lon] : null);
        $item->expiresAfter(self::CACHE_TTL);

        $this->osmCache->save($item);

        return $coordinate;
    }

    private function query(string $place): ?Coordinate
    {
        // Transport exceptions propagate so geocode() skips the cache write
        // (a transient 429/503 must not pin a place as unresolvable for 24h).
        /** @var list<array{lat?: string, lon?: string}> $data */
        $data = $this->nominatimClient->request('GET', '/search', [
            'query' => [
                'q' => $place,
                'format' => 'jsonv2',
                'limit' => 1,
                // Restrict to the supported coverage area so the model cannot
                // pull the route outside France + Benelux via a place name.
                'countrycodes' => 'fr,be,lu,nl',
            ],
        ])->toArray();

        $first = $data[0] ?? null;
        if (!isset($first['lat'], $first['lon'])) {
            return null;
        }

        return new Coordinate((float) $first['lat'], (float) $first['lon']);
    }
}
