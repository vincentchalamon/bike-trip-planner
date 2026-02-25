<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use App\RouteParser\GpxStreamRouteParser;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootCollectionRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?collection/(\d+)#';

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
        $collectionId = $matches[1];

        // Fetch collection metadata to get tour IDs
        $response = $this->komootClient->request('GET', \sprintf('/api/v007/collections/%s', $collectionId), [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            throw new \RuntimeException(\sprintf('Komoot collection %s returned HTTP %d.', $collectionId, $statusCode));
        }

        /** @var array{_embedded?: array{items?: list<array{id?: int|string}>}, name?: string} $data */
        $data = $response->toArray();
        $title = $data['name'] ?? \sprintf('Komoot Collection %s', $collectionId);

        $tourIds = [];
        foreach ($data['_embedded']['items'] ?? [] as $item) {
            if (isset($item['id'])) {
                $tourIds[] = (string) $item['id'];
            }
        }

        if ([] === $tourIds) {
            throw new \RuntimeException(\sprintf('Komoot collection %s contains no tours.', $collectionId));
        }

        $tracks = [];
        foreach ($tourIds as $tourId) {
            $gpxResponse = $this->komootClient->request('GET', \sprintf('/api/v007/tours/%s.gpx', $tourId), [
                'headers' => ['Accept' => 'application/gpx+xml'],
            ]);

            if (200 !== $gpxResponse->getStatusCode()) {
                continue;
            }

            $points = $this->routeParserRegistry->get(GpxStreamRouteParser::class)->parse($gpxResponse->getContent());
            if ([] !== $points) {
                $tracks[] = $points;
            }
        }

        if ([] === $tracks) {
            throw new \RuntimeException(\sprintf('Komoot collection %s yielded no valid tracks.', $collectionId));
        }

        return new RouteFetchResult(
            sourceType: SourceType::KOMOOT_COLLECTION,
            tracks: $tracks,
            title: $title,
        );
    }
}
