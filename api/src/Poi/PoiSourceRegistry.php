<?php

declare(strict_types=1);

namespace App\Poi;

use App\Geo\NearbyNameDeduplicator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly class PoiSourceRegistry
{
    /** @var list<PoiSourceInterface> */
    private array $sources;

    /**
     * @param iterable<PoiSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.poi_source')]
        iterable $sources,
        private NearbyNameDeduplicator $deduplicator,
    ) {
        $this->sources = iterator_to_array($sources, false);
    }

    /**
     * Reads every POI source along the corridor and collapses the OSM/DataTourisme
     * overlap by proximity + name (the DataTourisme entry is preferred on a tie).
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: string, category: string, lat: float, lon: float, wikidataId: string|null, source: string}>
     */
    public function fetchAllInCorridor(array $route, int $radiusMeters): array
    {
        $all = [];
        foreach ($this->sources as $source) {
            foreach ($source->fetchInCorridor($route, $radiusMeters) as $poi) {
                $all[] = $poi;
            }
        }

        /** @var list<array{name: string, category: string, lat: float, lon: float, wikidataId: string|null, source: string}> $deduped */
        $deduped = $this->deduplicator->dedupe($all);

        return $deduped;
    }
}
