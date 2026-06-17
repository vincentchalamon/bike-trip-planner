<?php

declare(strict_types=1);

namespace App\Osm;

interface FerryRepositoryInterface
{
    /**
     * Ferry crossings (osm.ferries) running within $toleranceMeters of the stage
     * line — i.e. the stage's route follows them. Each entry carries the point on
     * the ferry closest to the route, for the alert marker.
     *
     * @param list<array{lat: float, lon: float}> $stagePoints
     *
     * @return list<array{name: ?string, lat: float, lon: float}>
     */
    public function findNearStage(array $stagePoints, int $toleranceMeters): array;
}
