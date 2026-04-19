<?php

declare(strict_types=1);

namespace App\Wikidata;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WikidataClient implements WikidataClientInterface
{
    private const int DEFAULT_TTL = 604800; // 7 days

    public function __construct(
        #[Autowire(service: 'wikidata.client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.wikidata')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, array{type: string, value: string}>>
     */
    public function query(string $sparql): array
    {
        $cacheKey = 'wikidata.'.hash('xxh128', $sparql);

        try {
            /** @var list<array<string, array{type: string, value: string}>> $result */
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($sparql): array {
                $item->expiresAfter(self::DEFAULT_TTL);

                $response = $this->httpClient->request('GET', 'https://query.wikidata.org/sparql', [
                    'query' => ['query' => $sparql, 'format' => 'json'],
                ]);

                $data = $response->toArray();
                $results = $data['results'] ?? [];
                if (!\is_array($results)) {
                    return [];
                }

                $bindings = $results['bindings'] ?? [];
                if (!\is_array($bindings)) {
                    return [];
                }

                return array_values($bindings);
            });

            return $result;
        } catch (\Throwable $throwable) {
            $this->logger->warning('Wikidata SPARQL query failed, returning empty result.', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }
}
