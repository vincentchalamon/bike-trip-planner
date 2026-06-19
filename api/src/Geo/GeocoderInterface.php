<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Model\Coordinate;

/**
 * Forward geocoding: resolve a place name to a coordinate, scoped to the
 * supported coverage area (France + Benelux). Returns null when the place cannot
 * be resolved.
 */
interface GeocoderInterface
{
    public function geocode(string $place): ?Coordinate;
}
