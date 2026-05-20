<?php

declare(strict_types=1);

namespace App\InRide;

use App\Geo\GeoDistanceInterface;
use App\Geo\GeoPoint;

/**
 * Approximates the detour required to visit a point of interest from the current
 * rider position, without recomputing the GPX route.
 *
 * The POI is projected orthogonally on every segment of the remaining polyline,
 * the closest projection (smallest perpendicular distance) is chosen as the
 * rejoin point, and the detour is estimated using Haversine distances:
 *
 *     detour = d(from → poi) + d(poi → rejoin) − d(from → rejoin)
 *
 * Edge cases:
 *  - Empty polyline → throws {@see \InvalidArgumentException}.
 *  - POI "behind" the rider (raw `t < 0` on segment 0; raw detour is positive) → detour clamped to 0 and flagged.
 *  - POI further than {@see self::POI_FAR_THRESHOLD_METERS} from the route → flagged.
 */
final readonly class DetourCalculator
{
    /**
     * Perpendicular distance above which the POI is considered far from the route
     * and a warning flag is raised on the result.
     */
    public const float POI_FAR_THRESHOLD_METERS = 5_000.0;

    public function __construct(
        private GeoDistanceInterface $distance,
    ) {
    }

    /**
     * @param list<GeoPoint> $remainingRoute polyline of the rider's remaining itinerary,
     *                                       ordered from current position towards the end
     *
     * @throws \InvalidArgumentException when $remainingRoute is empty
     */
    public function calculate(GeoPoint $from, GeoPoint $poi, array $remainingRoute): DetourResult
    {
        if ([] === $remainingRoute) {
            throw new \InvalidArgumentException('Remaining route polyline must not be empty.');
        }

        $rejoinPoint = $remainingRoute[0];
        $segmentIndex = 0;
        $minPerpendicular = \PHP_FLOAT_MAX;
        $bestRawT = 0.0;

        $segmentsCount = \count($remainingRoute) - 1;
        if (0 === $segmentsCount) {
            $minPerpendicular = $this->distance->inMeters(
                $poi->lat,
                $poi->lon,
                $rejoinPoint->lat,
                $rejoinPoint->lon,
            );
        }

        for ($i = 0; $i < $segmentsCount; ++$i) {
            $a = $remainingRoute[$i];
            $b = $remainingRoute[$i + 1];

            [$projection, $rawT] = $this->projectOnSegment($poi, $a, $b);
            $perpendicular = $this->distance->inMeters(
                $poi->lat,
                $poi->lon,
                $projection->lat,
                $projection->lon,
            );

            if ($perpendicular < $minPerpendicular) {
                $minPerpendicular = $perpendicular;
                $rejoinPoint = $projection;
                $segmentIndex = $i;
                $bestRawT = $rawT;
            }
        }

        $straightLineToPoi = $this->distance->inMeters($from->lat, $from->lon, $poi->lat, $poi->lon);
        $poiToRejoin = $this->distance->inMeters($poi->lat, $poi->lon, $rejoinPoint->lat, $rejoinPoint->lon);
        $fromToRejoin = $this->distance->inMeters($from->lat, $from->lon, $rejoinPoint->lat, $rejoinPoint->lon);

        $rawDetour = $straightLineToPoi + $poiToRejoin - $fromToRejoin;
        // POI is behind the rider when its projection on the first segment is before the segment start
        // (rawT < 0 on segment 0) — the rejoin clamps to the rider's position so the detour would
        // require backtracking. Surface that explicitly by clamping to zero and flagging.
        $isBehind = 0 === $segmentIndex && $bestRawT < 0.0;
        $clamped = $rawDetour < 0.0 || $isBehind;
        $detour = $clamped ? 0.0 : $rawDetour;

        return new DetourResult(
            rejoinPoint: $rejoinPoint,
            segmentIndex: $segmentIndex,
            detourMeters: $detour,
            straightLineToPoiMeters: $straightLineToPoi,
            poiFarFromRoute: $minPerpendicular > self::POI_FAR_THRESHOLD_METERS,
            detourClampedToZero: $clamped,
        );
    }

    /**
     * Orthogonal projection of $poi onto the segment [$a, $b], performed in a local
     * equirectangular tangent plane centered on the segment. The plane approximation
     * is sufficient for typical bikepacking segment lengths (a few hundred meters to a
     * few kilometers) and avoids the cost of a full geodesic projection.
     *
     * The resulting parameter t is clamped to [0, 1] so the projection always lies on
     * the segment (extremities included), which is what we want for a "rejoin point".
     *
     * @return array{GeoPoint, float} the clamped projection point and the raw (unclamped) parameter t
     */
    private function projectOnSegment(GeoPoint $poi, GeoPoint $a, GeoPoint $b): array
    {
        if ($a->lat === $b->lat && $a->lon === $b->lon) {
            return [$a, 0.0];
        }

        // Use a local equirectangular projection: x scaled by cos(latitude) so degrees
        // of longitude are weighted comparably to degrees of latitude in distance terms.
        $latRefRad = deg2rad(($a->lat + $b->lat) / 2.0);
        $cosLat = cos($latRefRad);

        $ax = $a->lon * $cosLat;
        $ay = $a->lat;
        $bx = $b->lon * $cosLat;
        $by = $b->lat;
        $px = $poi->lon * $cosLat;
        $py = $poi->lat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $denominator = $dx * $dx + $dy * $dy;

        if (0.0 === $denominator) {
            return [$a, 0.0];
        }

        $rawT = (($px - $ax) * $dx + ($py - $ay) * $dy) / $denominator;
        $t = max(0.0, min(1.0, $rawT));

        return [
            new GeoPoint(
                lat: $a->lat + $t * ($b->lat - $a->lat),
                lon: $a->lon + $t * ($b->lon - $a->lon),
            ),
            $rawT,
        ];
    }
}
