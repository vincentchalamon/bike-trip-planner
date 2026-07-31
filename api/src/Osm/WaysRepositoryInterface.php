<?php

declare(strict_types=1);

namespace App\Osm;

interface WaysRepositoryInterface
{
    /**
     * Highway ways whose geometry runs within $radiusMeters of the route corridor,
     * each reduced to the tags the terrain analyzers read (surface, highway,
     * cycleway*, bicycle, maxspeed) plus the centroid and the length (m) of the
     * portion inside the corridor -- not of the whole way, so a way the route only
     * brushes weighs no more than the metres actually shared with it.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{lat: float, lon: float, surface: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
