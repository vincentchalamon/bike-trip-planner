<?php

declare(strict_types=1);

namespace App\Osm;

interface CycleRouteRepositoryInterface
{
    /**
     * Fraction (0..1) of the stage that follows a signed cycle route (within
     * $toleranceMeters of an osm.cycle_routes geometry) — the "on cycle network"
     * indicator. Returns 0 for an empty/degenerate stage or when no cycle route
     * runs near it.
     *
     * @param list<array{lat: float, lon: float}> $stagePoints
     */
    public function onNetworkFraction(array $stagePoints, int $toleranceMeters): float;
}
