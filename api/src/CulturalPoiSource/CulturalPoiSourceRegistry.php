<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class CulturalPoiSourceRegistry
{
    /** @var list<CulturalPoiSourceInterface> */
    private readonly array $sources;

    /**
     * @param iterable<CulturalPoiSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.cultural_poi_source')]
        iterable $sources,
    ) {
        $this->sources = iterator_to_array($sources, false);
    }

    /**
     * Fetches POIs from all enabled sources and merges the results.
     *
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string}>
     */
    public function fetchAllForStages(array $stageGeometries, int $radiusMeters): array
    {
        $all = [];

        foreach ($this->sources as $source) {
            if (!$source->isEnabled()) {
                continue;
            }

            foreach ($source->fetchForStages($stageGeometries, $radiusMeters) as $poi) {
                $all[] = $poi;
            }
        }

        return $all;
    }
}
