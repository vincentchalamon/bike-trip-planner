<?php

declare(strict_types=1);

namespace App\Routing;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ValhallaRoutingProvider implements RoutingProviderInterface
{
    public function __construct(
        #[Autowire(service: 'routing.client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.routing')]
        private CacheInterface $cache,
    ) {
    }

    public function calculateRoute(Coordinate $from, Coordinate $to, array $via = []): RoutingResult
    {
        $cacheKey = 'routing.'.hash('xxh128', serialize([$from, $to, $via]));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($from, $to, $via): RoutingResult {
            $locations = [
                ['lat' => $from->lat, 'lon' => $from->lon],
                ...array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $via,
                ),
                ['lat' => $to->lat, 'lon' => $to->lon],
            ];

            $response = $this->httpClient->request('POST', '/route', [
                'json' => [
                    'locations' => $locations,
                    'costing' => 'bicycle',
                    'costing_options' => [
                        'bicycle' => [
                            'bicycle_type' => 'Hybrid',
                            'cycling_speed' => 20.0,
                            'use_roads' => 0.5,
                            'use_hills' => 0.3,
                        ],
                    ],
                    'shape_format' => 'polyline6',
                    'directions_options' => [
                        'units' => 'km',
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if (400 === $statusCode) {
                $data = $response->toArray(false);
                $errorCode = $data['error_code'] ?? null;

                if (\in_array($errorCode, [170, 171], true)) {
                    throw new RoutingUnavailableException($data['error'] ?? 'Routing unavailable');
                }
            }

            $data = $response->toArray();
            $trip = $data['trip'];
            $leg = $trip['legs'][0];

            $coordinates = $this->decodePolyline6($leg['shape']);

            return new RoutingResult(
                coordinates: $coordinates,
                distance: $trip['summary']['length'] * 1000,
                elevationGain: $trip['summary']['elevation_gain'] ?? 0.0,
                duration: $trip['summary']['time'],
            );
        });
    }

    /**
     * @return list<Coordinate>
     */
    private function decodePolyline6(string $encoded): array
    {
        $coordinates = [];
        $index = 0;
        $lat = 0;
        $lon = 0;
        $length = \strlen($encoded);

        while ($index < $length) {
            $shift = 0;
            $result = 0;

            do {
                $byte = \ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lat += (($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1));

            $shift = 0;
            $result = 0;

            do {
                $byte = \ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lon += (($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1));

            $coordinates[] = new Coordinate(
                lat: $lat / 1e6,
                lon: $lon / 1e6,
            );
        }

        return $coordinates;
    }
}
