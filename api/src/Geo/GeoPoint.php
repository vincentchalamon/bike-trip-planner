<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * Immutable geographic coordinate (WGS84) used by in-ride geometry helpers.
 */
final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lon,
    ) {
    }
}
