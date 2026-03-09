<?php

declare(strict_types=1);

namespace App\Geo;

interface GeoDistanceInterface
{
    /**
     * Returns the distance in meters between two coordinate pairs.
     */
    public function inMeters(float $lat1, float $lon1, float $lat2, float $lon2): float;

    /**
     * Returns the distance in kilometers between two coordinate pairs.
     */
    public function inKilometers(float $lat1, float $lon1, float $lat2, float $lon2): float;
}
