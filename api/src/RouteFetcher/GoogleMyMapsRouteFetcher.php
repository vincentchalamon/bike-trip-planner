<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use App\RouteParser\KmlRouteParser;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GoogleMyMapsRouteFetcher implements RouteFetcherInterface
{
    private const string MYMAPS_PATTERN = '#^https://www\.google\.com/maps/d/(.+)#';

    private const string SHORT_LINK_PATTERN = '#^https://maps\.app\.goo\.gl/#';

    public function __construct(
        #[Autowire(service: 'google_mymaps.client')]
        private HttpClientInterface $googleMymapsClient,
        #[Autowire(service: 'app.route_parser_registry')]
        private ContainerInterface $routeParserRegistry,
    ) {
    }

    public function supports(string $url): bool
    {
        return preg_match(self::MYMAPS_PATTERN, $url) || preg_match(self::SHORT_LINK_PATTERN, $url);
    }

    public function fetch(string $url): RouteFetchResult
    {
        // Resolve short links by following redirects
        $resolvedUrl = $url;
        if (preg_match(self::SHORT_LINK_PATTERN, $url)) {
            $resolvedUrl = $this->resolveShortLink($url);
        }

        // Extract map ID and fetch KML export
        preg_match('#/maps/d/([^/\?]+)#', $resolvedUrl, $matches);
        if (!isset($matches[1])) {
            throw new \RuntimeException(\sprintf('Cannot extract Google My Maps ID from URL: %s', $resolvedUrl));
        }

        $mapId = $matches[1];
        $kmlUrl = \sprintf('/maps/d/%s/export?format=kml', $mapId);

        $response = $this->googleMymapsClient->request('GET', $kmlUrl);

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode) {
            throw new \RuntimeException(\sprintf('Google My Maps export returned HTTP %d.', $statusCode));
        }

        $kmlContent = $response->getContent();
        $points = $this->routeParserRegistry->get(KmlRouteParser::class)->parse($kmlContent);

        if ([] === $points) {
            throw new \RuntimeException('Google My Maps KML contains no track points.');
        }

        return new RouteFetchResult(
            sourceType: SourceType::GOOGLE_MYMAPS,
            tracks: [$points],
            title: \sprintf('Google My Maps %s', $mapId),
        );
    }

    private function resolveShortLink(string $shortUrl): string
    {
        // Follow redirect to get full Google Maps URL
        $response = $this->googleMymapsClient->request('GET', $shortUrl, [
            'max_redirects' => 5,
        ]);

        $redirectUrl = $response->getInfo('redirect_url');

        if (\is_string($redirectUrl) && '' !== $redirectUrl) {
            return $redirectUrl;
        }

        // If no redirect info, try to get the final URL from response
        return $shortUrl;
    }
}
