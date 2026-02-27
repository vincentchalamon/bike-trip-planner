<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\Enum\SourceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KomootTourRouteFetcher implements RouteFetcherInterface
{
    private const string PATTERN = '#^https://www\.komoot\.com/(?:[a-z]{2}-[a-z]{2}/)?tour/(\d+)#';

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
        $tourId = $matches[1];

        $response = $this->komootClient->request('GET', \sprintf('/tour/%s', $tourId), [
            'headers' => ['Accept' => 'text/html'],
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

        $html = $response->getContent();
        $tourData = $this->htmlExtractor->extractTourData($html);

        return new RouteFetchResult(
            sourceType: SourceType::KOMOOT_TOUR,
            tracks: [$tourData['coordinates']],
            title: $tourData['name'],
        );
    }
}
