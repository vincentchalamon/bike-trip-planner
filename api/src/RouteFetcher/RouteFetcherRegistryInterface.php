<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use RuntimeException;

/**
 * Resolves the appropriate route fetcher for a given source URL.
 */
interface RouteFetcherRegistryInterface
{
    /**
     * @throws RuntimeException When no fetcher supports the given URL
     */
    public function get(string $url): RouteFetcherInterface;
}
