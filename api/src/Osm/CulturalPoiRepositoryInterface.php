<?php

declare(strict_types=1);

namespace App\Osm;

interface CulturalPoiRepositoryInterface
{
    /**
     * Cultural POIs (museums, monuments, historic sites) whose geometry is within
     * $radiusMeters of the route corridor, nearest first (capped).
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, wikidata: ?string, openingHours: ?string, description: ?string, website: ?string, imageUrl: ?string, wikipediaUrl: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
