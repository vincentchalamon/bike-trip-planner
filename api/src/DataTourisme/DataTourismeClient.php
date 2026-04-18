<?php

declare(strict_types=1);

namespace App\DataTourisme;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private RateLimiterFactory $rateLimiter,
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
            /** @var array<string, mixed> */
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($path, $query, $ttl): array {
                $item->expiresAfter($ttl);

                $limiter = $this->rateLimiter->create('datatourisme');
                if (!$limiter->consume()->isAccepted()) {
                    throw new DataTourismeRateLimitException('DataTourisme rate limit reached.');
                }

                $response = $this->httpClient->request('GET', $path, ['query' => $query]);

                /** @var array<string, mixed> */
                return $response->toArray();
            });
        } catch (DataTourismeRateLimitException $e) {
            $this->logger->warning('DataTourisme rate limit reached, returning empty result.', [
                'error' => $e->getMessage(),
            ]);

            return ['results' => []];
        } catch (\Throwable $e) {
            $this->logger->warning('DataTourisme request failed, returning empty result.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

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
