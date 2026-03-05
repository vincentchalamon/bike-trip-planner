<?php

declare(strict_types=1);

namespace App\Scanner;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks whether the local Overpass instance is ready to serve queries.
 * Caches the result in-memory for 30 seconds to avoid hammering the endpoint.
 */
final class LocalOverpassStatusChecker
{
    private const int CACHE_TTL = 30;

    private ?bool $cachedStatus = null;

    private ?float $cachedAt = null;

    public function __construct(
        #[Autowire(service: 'overpass.local.client')]
        private readonly HttpClientInterface $localClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isReady(): bool
    {
        $now = microtime(true);

        if (null !== $this->cachedStatus && null !== $this->cachedAt && ($now - $this->cachedAt) < self::CACHE_TTL) {
            return $this->cachedStatus;
        }

        try {
            $response = $this->localClient->request('GET', '/api/status', [
                'timeout' => 1,
            ]);

            $this->cachedStatus = 200 === $response->getStatusCode();
        } catch (\Throwable $throwable) {
            $this->logger->debug('Local Overpass status check failed.', [
                'error' => $throwable->getMessage(),
            ]);

            $this->cachedStatus = false;
        }

        $this->cachedAt = $now;

        return $this->cachedStatus;
    }
}
