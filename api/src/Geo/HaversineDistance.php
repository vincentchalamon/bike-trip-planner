<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * Computes the great-circle distance between two geographic coordinates
 * using the Haversine formula.
 */
final readonly class HaversineDistance implements GeoDistanceInterface
{
    private const float EARTH_RADIUS_METERS = 6_371_000.0;

    /**
     * Returns the distance in meters between two coordinate pairs.
     */
    public function inMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Returns the distance in kilometers between two coordinate pairs.
     */
    public function inKilometers(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return $this->inMeters($lat1, $lon1, $lat2, $lon2) / 1000.0;
    }
}
