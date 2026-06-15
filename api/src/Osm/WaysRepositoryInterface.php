<?php

declare(strict_types=1);

namespace App\Osm;

interface WaysRepositoryInterface
{
    /**
     * Highway ways whose geometry is within $radiusMeters of the route corridor,
     * each reduced to its centroid, length (m) and the tags the terrain analyzers
     * read (surface, highway, cycleway*, bicycle, maxspeed).
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{lat: float, lon: float, surface: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
