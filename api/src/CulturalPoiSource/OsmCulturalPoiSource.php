<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use App\Osm\CulturalPoiRepositoryInterface;

final readonly class OsmCulturalPoiSource implements CulturalPoiSourceInterface
{
    public function __construct(
        private CulturalPoiRepositoryInterface $culturalPoiRepository,
    ) {
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array
    {
        $route = [] !== $stageGeometries ? array_merge(...$stageGeometries) : [];

        $pois = [];
        foreach ($this->culturalPoiRepository->findInCorridor($route, $radiusMeters) as $poi) {
            $pois[] = [
                'name' => $poi['name'] ?? $poi['category'],
                'type' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                'openingHours' => null,
                'estimatedPrice' => null,
                'description' => null,
                'wikidataId' => $poi['wikidata'],
                'source' => 'osm',
            ];
        }

        return $pois;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'osm';
    }
}
