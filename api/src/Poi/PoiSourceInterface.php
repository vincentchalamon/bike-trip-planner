<?php

declare(strict_types=1);

namespace App\Poi;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.poi_source')]
interface PoiSourceInterface
{
    /**
     * Resupply/POI candidates within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: string, category: string, lat: float, lon: float, wikidataId: string|null, source: string}>
     */
    public function fetchInCorridor(array $route, int $radiusMeters): array;
}
