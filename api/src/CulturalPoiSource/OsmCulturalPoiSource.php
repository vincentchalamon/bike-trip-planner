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
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string, imageUrl: string|null, wikipediaUrl: string|null}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array
    {
        $route = [] !== $stageGeometries ? array_merge(...$stageGeometries) : [];

        $pois = [];
        // openingHours/description/imageUrl/wikipediaUrl are enriched from Wikidata
        // at provision time (ADR-041); no runtime SPARQL call.
        foreach ($this->culturalPoiRepository->findInCorridor($route, $radiusMeters) as $poi) {
            $pois[] = [
                'name' => $poi['name'] ?? $poi['category'],
                'type' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                'openingHours' => $poi['openingHours'],
                'estimatedPrice' => null,
                'description' => $poi['description'],
                'wikidataId' => $poi['wikidata'],
                'source' => 'osm',
                'imageUrl' => $poi['imageUrl'],
                'wikipediaUrl' => $poi['wikipediaUrl'],
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
