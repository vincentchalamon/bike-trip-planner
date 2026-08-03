<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use App\Tourism\CulturalPoiRepositoryInterface;

/**
 * Cultural POIs from the local-first `tourism` schema (DataTourisme flux imported
 * by the provisioner), read along the route corridor. Replaces the runtime
 * DataTourisme REST API (ADR-040); the JSON-LD mapping now lives in the
 * provisioner's DataTourismeMapper.
 */
final readonly class DataTourismeCulturalPoiSource implements CulturalPoiSourceInterface
{
    public function __construct(
        private CulturalPoiRepositoryInterface $culturalPoiRepository,
    ) {
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string|null, type: string, lat: float, lon: float, osmType: string|null, osmId: int|null, openingHours: string|null, website: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string, imageUrl: string|null, wikipediaUrl: string|null}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array
    {
        $route = [] !== $stageGeometries ? array_merge(...$stageGeometries) : [];

        $pois = [];
        foreach ($this->culturalPoiRepository->findInCorridor($route, $radiusMeters) as $poi) {
            $pois[] = [
                // A nameless POI stays nameless: the display label is resolved
                // downstream (PoiLabelResolver), after deduplication.
                'name' => $poi['name'],
                'type' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                // A curated entry has no OSM identity: there is no join key from the flux.
                'osmType' => null,
                'osmId' => null,
                'openingHours' => $poi['openingHours'],
                // The flux mapping does not carry a website for cultural POIs.
                'website' => null,
                'estimatedPrice' => null,
                'description' => $poi['description'],
                'wikidataId' => $poi['wikidata'],
                'source' => 'datatourisme',
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
        return 'datatourisme';
    }
}
