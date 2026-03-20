<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use App\RouteParser\GpxRouteParserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches routes from Strava public route pages.
 *
 * Strava exposes a GPX export endpoint for public routes at /routes/{id}/export_gpx.
 * The fetcher downloads the GPX content and delegates parsing to the existing GpxStreamRouteParser.
 */
final readonly class StravaRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.strava\.com/routes/(\d+)#';

    public function __construct(
        #[Autowire(service: 'strava.client')]
        private HttpClientInterface $stravaClient,
        private GpxRouteParserInterface $gpxParser,
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

        $cacheKey = 'route_fetch.strava_route.'.$routeId;

        return $this->routeCache->get($cacheKey, function (ItemInterface $item) use ($routeId): RouteFetchResult {
            $item->expiresAfter(86400);

            $response = $this->stravaClient->request('GET', \sprintf('/routes/%s/export_gpx', $routeId));

            $statusCode = $response->getStatusCode();

            if (404 === $statusCode) {
                throw new \RuntimeException(\sprintf('Strava route %s not found (404).', $routeId));
            }

            if (403 === $statusCode) {
                throw new \RuntimeException(\sprintf('Strava route %s is private or access denied (403).', $routeId));
            }

            if (200 !== $statusCode) {
                throw new \RuntimeException(\sprintf('Strava route %s returned HTTP %d.', $routeId, $statusCode));
            }

            $gpxContent = $response->getContent();
            $coordinates = $this->gpxParser->parse($gpxContent);

            if ([] === $coordinates) {
                throw new \RuntimeException(\sprintf('Strava route %s yielded no valid coordinates.', $routeId));
            }

            $title = $this->gpxParser->extractTitle($gpxContent);

            return new RouteFetchResult(
                sourceType: SourceType::STRAVA_ROUTE,
                tracks: [$coordinates],
                title: $title,
            );
        });
    }
}
