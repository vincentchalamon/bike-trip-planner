<?php

declare(strict_types=1);

namespace App\InRide;

use App\Geo\GeoDistanceInterface;
use App\Geo\GeoPoint;

/**
 * Truncates a stage polyline at the rider's current position so the remaining
 * itinerary starts exactly where the rider is.
 *
 * {@see DetourCalculator} carries a "POI behind the rider" bound that only fires on
 * the first segment (`segmentIndex === 0 && rawT < 0`). Feeding it the WHOLE stage
 * polyline makes that bound unreachable (the rider sits in the middle), so a POI
 * already passed would score a positive detour. `from()` returns a polyline that
 * begins at the rider's projection, restoring the bound.
 *
 * The rider is projected with {@see DetourCalculator::projectOnSegment()} — the same
 * equirectangular projection the detour uses — onto the nearest segment.
 */
final readonly class RouteTail
{
    public function __construct(
        private GeoDistanceInterface $distance,
    ) {
    }

    /**
     * @param list<GeoPoint> $polyline stage polyline, ordered from start to end
     *
     * @return list<GeoPoint> the rider's projection, followed by the polyline points ahead of it
     */
    public function from(GeoPoint $rider, array $polyline): array
    {
        if ([] === $polyline) {
            return [];
        }

        $segmentsCount = \count($polyline) - 1;
        if (0 === $segmentsCount) {
            return [$polyline[0]];
        }

        $bestIndex = 0;
        $bestProjection = $polyline[0];
        $bestRawT = 0.0;
        $minPerpendicular = \PHP_FLOAT_MAX;

        for ($i = 0; $i < $segmentsCount; ++$i) {
            [$projection, $rawT] = DetourCalculator::projectOnSegment($rider, $polyline[$i], $polyline[$i + 1]);
            $perpendicular = $this->distance->inMeters($rider->lat, $rider->lon, $projection->lat, $projection->lon);

            if ($perpendicular < $minPerpendicular) {
                $minPerpendicular = $perpendicular;
                $bestIndex = $i;
                $bestProjection = $projection;
                $bestRawT = $rawT;
            }
        }

        // The projection leads the tail. Append only the polyline points strictly ahead
        // of it: when the rider sits at (or past) the segment end (rawT >= 1) the clamped
        // projection coincides with polyline[bestIndex + 1], so skip that point to avoid a
        // duplicate; otherwise the next point is polyline[bestIndex + 1].
        $firstAhead = $bestRawT >= 1.0 ? $bestIndex + 2 : $bestIndex + 1;

        $tail = [$bestProjection];
        for ($i = $firstAhead, $count = \count($polyline); $i < $count; ++$i) {
            $tail[] = $polyline[$i];
        }

        return $tail;
    }
}
