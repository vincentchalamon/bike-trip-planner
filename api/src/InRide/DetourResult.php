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
 *   Clamped to 0 either when the raw Haversine sum is slightly negative (floating-point
 *   noise on a POI essentially on the route) or when the POI is behind the rider (the
 *   detour is positive but represents unavoidable backtracking).
 * - $straightLineToPoiMeters: Haversine distance between the rider position and the POI.
 * - $poiFarFromRoute: true when the perpendicular distance from the POI to the route
 *   exceeds the {@see DetourCalculator::POI_FAR_THRESHOLD_METERS} warning threshold.
 * - $detourClampedToZero: true when `detourMeters` was clamped to 0, either because the
 *   raw Haversine sum was slightly negative or because the POI is behind the rider
 *   (raw `t < 0` on segment 0).
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
