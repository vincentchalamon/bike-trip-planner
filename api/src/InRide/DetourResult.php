<?php

declare(strict_types=1);

namespace App\InRide;

use App\Geo\GeoPoint;

/**
 * Outcome of a detour approximation computed by {@see DetourCalculator}.
 *
 * - $rejoinPoint: orthogonal projection of the POI on the remaining route — where the rider
 *   is expected to resume the original itinerary after visiting the POI.
 * - $segmentIndex: index of the segment of the polyline (i.e. between point $i and $i+1)
 *   on which $rejoinPoint lies.
 * - $detourMeters: estimated additional distance in meters compared to staying on the route.
 *   Clamped to 0 when the POI is behind the rider (negative raw value).
 * - $straightLineToPoiMeters: Haversine distance between the rider position and the POI.
 * - $poiFarFromRoute: true when the perpendicular distance from the POI to the route
 *   exceeds the {@see DetourCalculator::POI_FAR_THRESHOLD_METERS} warning threshold.
 * - $detourClampedToZero: true when the raw detour value was negative (POI behind the rider)
 *   and has been clamped to 0.
 */
final readonly class DetourResult
{
    public function __construct(
        public GeoPoint $rejoinPoint,
        public int $segmentIndex,
        public float $detourMeters,
        public float $straightLineToPoiMeters,
        public bool $poiFarFromRoute = false,
        public bool $detourClampedToZero = false,
    ) {
    }
}
