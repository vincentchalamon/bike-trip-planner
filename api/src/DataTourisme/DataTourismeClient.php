<?php

declare(strict_types=1);

namespace App\DataTourisme;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DataTourismeClient implements DataTourismeClientInterface
{
    private const int DEFAULT_TTL = 86400; // 24 hours

    public function __construct(
        #[Autowire(service: 'datatourisme.client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.datatourisme')]
        private CacheInterface $cache,
        #[Autowire(service: 'limiter.datatourisme')]
        private RateLimiterFactoryInterface $rateLimiter,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::DATATOURISME_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'bool:default::DATATOURISME_ENABLED')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && '' !== $this->apiKey;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function request(string $path, array $query = [], ?int $ttlSeconds = null): array
    {
        $cacheKey = $this->cacheKey($path, $query);
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL;

        try {
            /** @var array<string, mixed> $result */
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($path, $query, $ttl): array {
                $item->expiresAfter($ttl);

                $limiter = $this->rateLimiter->create('datatourisme');
                if (!$limiter->consume()->isAccepted()) {
                    throw new DataTourismeRateLimitException('DataTourisme rate limit reached.');
                }

                $response = $this->httpClient->request('GET', $path, ['query' => $query]);

                return $response->toArray();
            });

            return $result;
        } catch (\Throwable $throwable) {
            if ($throwable instanceof DataTourismeRateLimitException) {
                $this->logger->warning('DataTourisme rate limit reached, returning empty result.', [
                    'error' => $throwable->getMessage(),
                ]);
            } else {
                $this->logger->warning('DataTourisme request failed, returning empty result.', [
                    'path' => $path,
                    'error' => $throwable->getMessage(),
                ]);
            }

            return ['results' => []];
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function cacheKey(string $path, array $query): string
    {
        return 'datatourisme.'.hash('xxh128', $path.serialize($query));
    }
}
