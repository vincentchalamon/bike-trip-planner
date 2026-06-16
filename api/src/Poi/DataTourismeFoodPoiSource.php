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
                'name' => $poi['name'] ?? $poi['category'],
                'category' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                'wikidataId' => $poi['wikidata'],
                'source' => 'datatourisme',
            ];
        }

        return $pois;
    }
}
