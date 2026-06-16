<?php

declare(strict_types=1);

namespace App\Osm;

interface CoverageRepositoryInterface
{
    /**
     * Returns true when the route lies (even partly) outside the provisioned
     * coverage polygon (osm.coverage) — i.e. Valhalla has no routing tiles for
     * it, so the trip is display-only. Returns false when the route is fully
     * covered, when given no points, or when coverage was never provisioned
     * (unknown coverage must not block the user).
     *
     * @param list<array{lat: float, lon: float}> $points
     */
    public function isRouteOutOfZone(array $points): bool;
}
