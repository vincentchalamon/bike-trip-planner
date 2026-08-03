<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cultural_poi_source')]
interface CulturalPoiSourceInterface
{
    /**
     * Cultural POI candidates along the stage geometries.
     *
     * `name` is null for a POI the source has no proper name for; the localised
     * display label is resolved downstream by {@see \App\Poi\PoiLabelResolver}.
     *
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string|null, type: string, lat: float, lon: float, osmType: string|null, osmId: int|null, openingHours: string|null, website: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string, imageUrl: string|null, wikipediaUrl: string|null}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array;

    public function isEnabled(): bool;

    public function getName(): string;
}
