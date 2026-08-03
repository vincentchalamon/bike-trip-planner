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
     * The (osmType, osmId) pair is the primary key of the Tier-1 index and the only
     * stable identity an OSM entry has: it lets the rider open the object on
     * openstreetmap.org to check it still exists, or fix it at the source. Null for
     * a DataTourisme entry, which carries no OSM identity.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: string|null, category: string, lat: float, lon: float, osmType: string|null, osmId: int|null, openingHours: string|null, website: string|null, wikidataId: string|null, source: string}>
     */
    public function fetchInCorridor(array $route, int $radiusMeters): array;
}
