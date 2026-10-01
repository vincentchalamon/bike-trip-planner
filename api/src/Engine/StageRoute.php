<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;

/**
 * The route a trip's stages cover, read back from their persisted geometry.
 *
 * The points a route is parsed into live in `cache.trip_state` for half an hour and are never
 * refreshed (ADR-022); the stages are durable, and each carries its own slice of the route. Once
 * the points are gone, this is the route — and the current one, since every structural edit
 * rewrites the geometry it touches.
 *
 * A stage's geometry is the decimated slice simplified once more at the same 20 m tolerance as
 * the decimation, so what comes back is the decimated route to within that tolerance. The raw
 * points are not recoverable: elevation is then read from the decimated profile.
 */
final class StageRoute
{
    /**
     * The whole route as one line. Consecutive stages share their boundary point
     * (stage[i].end == stage[i+1].start), so the first point of every stage after the first is
     * dropped rather than counted twice. Rest days carry no geometry and add nothing.
     *
     * @param list<Stage> $stages
     *
     * @return list<Coordinate>
     */
    public static function points(array $stages): array
    {
        $points = [];
        $first = true;
        foreach ($stages as $stage) {
            foreach ($stage->geometry as $offset => $point) {
                if (!$first && 0 === $offset) {
                    continue;
                }

                $points[] = $point;
            }

            if ([] !== $stage->geometry) {
                $first = false;
            }
        }

        return $points;
    }

    /**
     * One track per stage that has a route, in order: what a multi-track source was split into.
     *
     * @param list<Stage> $stages
     *
     * @return list<list<Coordinate>>
     */
    public static function tracks(array $stages): array
    {
        return array_values(array_filter(
            array_map(static fn (Stage $stage): array => $stage->geometry, $stages),
            static fn (array $geometry): bool => [] !== $geometry,
        ));
    }
}
