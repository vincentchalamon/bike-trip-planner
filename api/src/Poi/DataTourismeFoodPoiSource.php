<?php

declare(strict_types=1);

namespace App\Poi;

use App\Tourism\FoodPoiRepositoryInterface;

/**
 * DataTourisme contribution to the resupply scan: the tourism.food_pois layer
 * (eateries + food shops). Merged with the OSM pois by proximity + name in the
 * registry, the curated DataTourisme entry winning on a tie (ADR-040).
 */
final readonly class DataTourismeFoodPoiSource implements PoiSourceInterface
{
    public function __construct(private FoodPoiRepositoryInterface $foodPoiRepository)
    {
    }

    public function fetchInCorridor(array $route, int $radiusMeters): array
    {
        $pois = [];
        foreach ($this->foodPoiRepository->findInCorridor($route, $radiusMeters) as $poi) {
            $pois[] = [
                // A nameless POI stays nameless: the display label is resolved
                // downstream (PoiLabelResolver), after deduplication.
                'name' => $poi['name'],
                'category' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                // A curated entry has no OSM identity: there is no join key from the flux.
                'osmType' => null,
                'osmId' => null,
                'openingHours' => $poi['openingHours'],
                // The flux mapping does not carry a website for food POIs.
                'website' => null,
                'wikidataId' => $poi['wikidata'],
                'source' => 'datatourisme',
            ];
        }

        return $pois;
    }
}
