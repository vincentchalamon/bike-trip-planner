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
}
