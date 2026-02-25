<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use App\RouteParser\GpxStreamRouteParser;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootTourRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?tour/(\d+)#';

    public function __construct(
        #[Autowire(service: 'komoot.client')]
        private HttpClientInterface $komootClient,
        #[Autowire(service: 'app.route_parser_registry')]
        private ContainerInterface $routeParserRegistry,
    ) {
    }

    public function supports(string $url): bool
    {
        return (bool) preg_match(self::PATTERN, $url);
    }

    public function fetch(string $url): RouteFetchResult
    {
        preg_match(self::PATTERN, $url, $matches);
        $tourId = $matches[1];

        $response = $this->komootClient->request('GET', \sprintf('/api/v007/tours/%s.gpx', $tourId), [
            'headers' => ['Accept' => 'application/gpx+xml'],
        ]);

        $statusCode = $response->getStatusCode();

        if (404 === $statusCode) {
            throw new \RuntimeException(\sprintf('Komoot tour %s not found (404).', $tourId));
        }

        if (403 === $statusCode) {
            throw new \RuntimeException(\sprintf('Komoot tour %s is private or access denied (403).', $tourId));
        }

        if (200 !== $statusCode) {
            throw new \RuntimeException(\sprintf('Komoot tour %s returned HTTP %d.', $tourId, $statusCode));
        }

        $gpxContent = $response->getContent();
        $points = $this->routeParserRegistry->get(GpxStreamRouteParser::class)->parse($gpxContent);

        if ([] === $points) {
            throw new \RuntimeException(\sprintf('Komoot tour %s GPX contains no track points.', $tourId));
        }

        return new RouteFetchResult(
            sourceType: SourceType::KOMOOT_TOUR,
            tracks: [$points],
            title: \sprintf('Komoot Tour %s', $tourId),
        );
    }
}
