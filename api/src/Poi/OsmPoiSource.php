<?php

declare(strict_types=1);

namespace App\Poi;

use App\Osm\PoiRepositoryInterface;

/**
 * OSM contribution to the resupply scan: the osm.pois Tier-1 index (ADR-040).
 */
final readonly class OsmPoiSource implements PoiSourceInterface
{
    public function __construct(private PoiRepositoryInterface $poiRepository)
    {
    }

    public function fetchInCorridor(array $route, int $radiusMeters): array
    {
        $pois = [];
        foreach ($this->poiRepository->findInCorridor($route, $radiusMeters) as $poi) {
            $pois[] = [
                // A nameless POI stays nameless: the display label is resolved
                // downstream (PoiLabelResolver), after deduplication.
                'name' => $poi['name'],
                'category' => $poi['category'],
                'lat' => $poi['lat'],
                'lon' => $poi['lon'],
                'wikidataId' => null,
                'source' => 'osm',
            ];
        }

        return $pois;
    }
}
