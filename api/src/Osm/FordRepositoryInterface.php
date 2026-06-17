<?php

declare(strict_types=1);

namespace App\Osm;

interface FordRepositoryInterface
{
    /**
     * Fords (osm.fords) within $toleranceMeters of the stage line — i.e. the
     * route crosses or passes one. Each entry carries the ford's own position
     * for the alert marker.
     *
     * @param list<array{lat: float, lon: float}> $stagePoints
     *
     * @return list<array{name: ?string, lat: float, lon: float}>
     */
    public function findNearStage(array $stagePoints, int $toleranceMeters): array;
}
