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
     * `name` is null for a POI the source has no proper name for; the localised
     * display label is resolved downstream by {@see PoiLabelResolver}.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: string|null, category: string, lat: float, lon: float, wikidataId: string|null, source: string}>
     */
    public function fetchInCorridor(array $route, int $radiusMeters): array;
}
