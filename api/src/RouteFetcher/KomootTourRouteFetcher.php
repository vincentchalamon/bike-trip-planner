<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use RuntimeException;
use App\Enum\SourceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootTourRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?tour/(\d+)#';

    public function __construct(
        #[Autowire(service: 'komoot.client')]
        private HttpClientInterface $komootClient,
        private KomootHtmlExtractor $htmlExtractor,
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
        $tourId = $matches[1];

        $cacheKey = 'route_fetch.komoot_tour.'.$tourId;

        return $this->routeCache->get($cacheKey, function (ItemInterface $item) use ($tourId): RouteFetchResult {
            $item->expiresAfter(86400);

            $response = $this->komootClient->request('GET', \sprintf('/tour/%s', $tourId), [
                'headers' => ['Accept' => 'text/html'],
            ]);

            $statusCode = $response->getStatusCode();

            if (404 === $statusCode) {
                throw new RuntimeException(\sprintf('Komoot tour %s not found (404).', $tourId));
            }

            if (403 === $statusCode) {
                throw new RuntimeException(\sprintf('Komoot tour %s is private or access denied (403).', $tourId));
            }

            if (200 !== $statusCode) {
                throw new RuntimeException(\sprintf('Komoot tour %s returned HTTP %d.', $tourId, $statusCode));
            }

            $html = $response->getContent();
            $tourData = $this->htmlExtractor->extractTourData($html);

            return new RouteFetchResult(
                sourceType: SourceType::KOMOOT_TOUR,
                tracks: [$tourData['coordinates']],
                title: $tourData['name'],
            );
        });
    }
}
