<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CulturalPoiSourceRegistry
{
    /** @var list<CulturalPoiSourceInterface> */
    private array $sources;

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
     * Fetches POIs from all enabled sources, merges and deduplicates by wikidataId.
     * When both OSM and DataTourisme provide a POI with the same wikidataId,
     * the DataTourisme entry is preferred.
     *
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array>
     */
    public function fetchAllForStages(array $stageGeometries, int $radiusMeters): array
    {
        $all = [];

        foreach ($this->sources as $source) {
            if (!$source->isEnabled()) {
                continue;
            }

            foreach ($source->fetchForStages($stageGeometries, $radiusMeters) as $poi) {
                $wikidataId = $poi['wikidataId'] ?? null;
                if (null !== $wikidataId && isset($all[$wikidataId])) {
                    if ('datatourisme' === ($poi['source'] ?? null)) {
                        $all[$wikidataId] = $poi;
                    }
                    continue;
                }
                $key = null !== $wikidataId ? $wikidataId : spl_object_id((object) $poi);
                $all[$key] = $poi;
            }
        }

        return array_values($all);
    }
}
