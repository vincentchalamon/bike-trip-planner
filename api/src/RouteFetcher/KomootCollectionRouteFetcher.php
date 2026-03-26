<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use RuntimeException;
use App\Enum\SourceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootCollectionRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?collection/(\d+)#';

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
        $collectionId = $matches[1];

        $cacheKey = 'route_fetch.komoot_collection.'.$collectionId;

        return $this->routeCache->get($cacheKey, function (ItemInterface $item) use ($collectionId): RouteFetchResult {
            $item->expiresAfter(86400);

            // Fetch collection page to extract tour IDs
            $response = $this->komootClient->request('GET', \sprintf('/collection/%s', $collectionId), [
                'headers' => ['Accept' => 'text/html'],
            ]);

            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                throw new RuntimeException(\sprintf('Komoot collection %s returned HTTP %d.', $collectionId, $statusCode));
            }

            $html = $response->getContent();
            $collectionData = $this->htmlExtractor->extractCollectionTourIds($html);
            $title = $collectionData['name'];
            $tourIds = $collectionData['tourIds'];

            // Fire all tour requests (non-blocking — Symfony HttpClient multiplexes concurrently)
            $responses = [];
            foreach ($tourIds as $tourId) {
                $responses[$tourId] = $this->komootClient->request('GET', \sprintf('/tour/%s', $tourId), [
                    'headers' => ['Accept' => 'text/html'],
                ]);
            }

            // Collect results (HttpClient resolves responses concurrently on first access)
            // Cache each tour individually so that subsequent per-tour requests hit cache (ADR-016 Option E)
            $tracks = [];
            foreach ($responses as $tourId => $response) {
                if (200 !== $response->getStatusCode()) {
                    continue;
                }

                try {
                    $tourData = $this->htmlExtractor->extractTourData($response->getContent());
                    $tracks[] = $tourData['coordinates'];

                    // Warm per-tour cache (same key as KomootTourRouteFetcher)
                    $tourCacheKey = 'route_fetch.komoot_tour.'.$tourId;
                    $this->routeCache->get($tourCacheKey, static function (ItemInterface $tourItem) use ($tourData): RouteFetchResult {
                        $tourItem->expiresAfter(86400);

                        return new RouteFetchResult(
                            sourceType: SourceType::KOMOOT_TOUR,
                            tracks: [$tourData['coordinates']],
                            title: $tourData['name'],
                        );
                    });
                } catch (RuntimeException) {
                    continue;
                }
            }

            if ([] === $tracks) {
                throw new RuntimeException(\sprintf('Komoot collection %s yielded no valid tracks.', $collectionId));
            }

            return new RouteFetchResult(
                sourceType: SourceType::KOMOOT_COLLECTION,
                tracks: $tracks,
                title: $title,
            );
        });
    }
}
