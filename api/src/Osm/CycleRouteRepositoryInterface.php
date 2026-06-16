<?php

declare(strict_types=1);

namespace App\Osm;

interface CycleRouteRepositoryInterface
{
    /**
     * For each stage, the fraction (0..1) that follows a signed cycle route
     * (within $toleranceMeters of an osm.cycle_routes geometry) — the per-stage
     * "on cycle network" indicator. Computed for every stage in a single query.
     * A stage yields 0 when it has fewer than two points or no cycle route runs
     * near it.
     *
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<float> one fraction per stage, in the same order
     */
    public function onNetworkFractions(array $stageGeometries, int $toleranceMeters): array;
}
