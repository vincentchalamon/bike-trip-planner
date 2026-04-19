<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cultural_poi_source')]
interface CulturalPoiSourceInterface
{
    /**
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array;

    public function isEnabled(): bool;

    public function getName(): string;
}
