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
     * Executes multiple Overpass QL queries concurrently with cache lookup and two-wave
     * fallback (local → public). Cached results are returned immediately; uncached queries
     * are fired in parallel.
     *
     * @param array<string, string> $queries Map of logical name => Overpass QL query
     *
     * @return array<string, array<string, mixed>> Map of logical name => parsed JSON result
     */
    public function queryBatch(array $queries): array;
}
