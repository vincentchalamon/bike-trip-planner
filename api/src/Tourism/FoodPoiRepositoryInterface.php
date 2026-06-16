<?php

declare(strict_types=1);

namespace App\Tourism;

interface FoodPoiRepositoryInterface
{
    /**
     * DataTourisme food POIs (eateries + food shops) within $radiusMeters of the
     * route corridor, nearest first.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, openingHours: ?string, description: ?string, wikidata: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
