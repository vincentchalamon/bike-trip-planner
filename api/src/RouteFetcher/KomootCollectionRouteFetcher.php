<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootCollectionRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?collection/(\d+)#';

    public function __construct(
        #[Autowire(service: 'komoot.client')]
        private HttpClientInterface $komootClient,
        private KomootHtmlExtractor $htmlExtractor,
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

        // Fetch collection page to extract tour IDs
        $response = $this->komootClient->request('GET', \sprintf('/collection/%s', $collectionId), [
            'headers' => ['Accept' => 'text/html'],
        ]);

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
            throw new \RuntimeException(\sprintf('Komoot collection %s returned HTTP %d.', $collectionId, $statusCode));
        }

        $html = $response->getContent();
        $collectionData = $this->htmlExtractor->extractCollectionTourIds($html);
        $title = $collectionData['name'];
        $tourIds = $collectionData['tourIds'];

        // Fetch each tour's coordinates from its HTML page
        $tracks = [];
        foreach ($tourIds as $tourId) {
            $tourResponse = $this->komootClient->request('GET', \sprintf('/tour/%s', $tourId), [
                'headers' => ['Accept' => 'text/html'],
            ]);

            if (200 !== $tourResponse->getStatusCode()) {
                continue;
            }

            try {
                $tourData = $this->htmlExtractor->extractTourData($tourResponse->getContent());
                $tracks[] = $tourData['coordinates'];
            } catch (\RuntimeException) {
                continue;
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
