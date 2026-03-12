<?php

declare(strict_types=1);

namespace App\Scanner;

/**
 * Scans a route against a data source (e.g. OSM) and returns POIs, accommodations, bike shops, etc. along the route.
 */
interface ScannerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function query(string $query): array;

    /**
     * Executes multiple queries concurrently with cache lookup and graceful degradation.
     * Cached results are returned immediately; uncached queries are fired in parallel.
     * Implementations may use fallback strategies for unavailable data sources.
     *
     * @param array<string, string> $queries Map of logical name => query string
     *
     * @return array<string, array<string, mixed>> Map of logical name => parsed result (empty array on failure)
     */
    public function queryBatch(array $queries): array;
}
