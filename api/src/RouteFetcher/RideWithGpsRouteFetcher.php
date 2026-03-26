<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use RuntimeException;
use App\ApiResource\Model\Coordinate;
use App\Enum\SourceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches routes from RideWithGPS public route pages.
 *
 * RideWithGPS exposes a public JSON endpoint at /routes/{id}.json containing
 * track points with lat/lng/elevation. This avoids GPX parsing overhead.
 */
final readonly class RideWithGpsRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://ridewithgps\.com/routes/(\d+)#';

    public function __construct(
        #[Autowire(service: 'ridewithgps.client')]
        private HttpClientInterface $rwgpsClient,
        #[Autowire(service: 'cache.route_fetch')]
        private CacheInterface $routeCache,
    ) {
    }

    public function supports(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function fetch(string $url): RouteFetchResult
    {
        preg_match(self::PATTERN, $url, $matches);
        $routeId = $matches[1];

        $cacheKey = 'route_fetch.ridewithgps_route.'.$routeId;

        return $this->routeCache->get($cacheKey, function (ItemInterface $item) use ($routeId): RouteFetchResult {
            $item->expiresAfter(86400);

            $response = $this->rwgpsClient->request('GET', \sprintf('/routes/%s.json', $routeId));

            $statusCode = $response->getStatusCode();

            if (404 === $statusCode) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s not found (404).', $routeId));
            }

            if (403 === $statusCode) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s is private or access denied (403).', $routeId));
            }

            if (200 !== $statusCode) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s returned HTTP %d.', $routeId, $statusCode));
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray();

            /** @var array<string, mixed>|null $route */
            $route = \is_array($data['route'] ?? null) ? $data['route'] : null;
            if (null === $route) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s has no route data.', $routeId));
            }

            $title = \is_string($route['name'] ?? null) ? $route['name'] : null;

            /** @var list<array{lat?: mixed, lng?: mixed, e?: mixed}> $trackPoints */
            $trackPoints = \is_array($route['track_points'] ?? null) ? $route['track_points'] : [];

            if ([] === $trackPoints) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s has no track points.', $routeId));
            }

            $coordinates = [];
            foreach ($trackPoints as $point) {
                if (!\is_array($point)) {
                    continue;
                }

                $lat = $point['lat'] ?? null;
                $lng = $point['lng'] ?? null;
                $ele = $point['e'] ?? 0.0;

                if (!is_numeric($lat) || !is_numeric($lng)) {
                    continue;
                }

                $coordinates[] = new Coordinate(
                    lat: (float) $lat,
                    lon: (float) $lng,
                    ele: is_numeric($ele) ? (float) $ele : 0.0,
                );
            }

            if ([] === $coordinates) {
                throw new RuntimeException(\sprintf('RideWithGPS route %s yielded no valid coordinates.', $routeId));
            }

            return new RouteFetchResult(
                sourceType: SourceType::RIDE_WITH_GPS,
                tracks: [$coordinates],
                title: $title,
            );
        });
    }
}
