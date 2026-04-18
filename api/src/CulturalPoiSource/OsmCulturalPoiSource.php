<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use App\ApiResource\Model\Coordinate;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;

final readonly class OsmCulturalPoiSource implements CulturalPoiSourceInterface
{
    /**
     * @var list<string>
     */
    private const array NOTABLE_HISTORIC_VALUES = [
        'castle',
        'monument',
        'memorial',
        'ruins',
        'archaeological_site',
        'church',
        'cathedral',
        'abbey',
        'fort',
    ];

    public function __construct(
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
    ) {
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: null, estimatedPrice: null, description: null, wikidataId: string|null, source: string}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array
    {
        $coordinateGeometries = array_map(
            static fn (array $geometry): array => array_map(
                static fn (array $point): Coordinate => new Coordinate($point['lat'], $point['lon']),
                $geometry,
            ),
            $stageGeometries,
        );

        $query = $this->queryBuilder->buildBatchCulturalPoiQuery($coordinateGeometries, $radiusMeters);
        $result = $this->scanner->query($query);

        /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
        $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

        $pois = [];
        foreach ($elements as $element) {
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

            if (null === $lat || null === $lon) {
                continue;
            }

            $tags = $element['tags'] ?? [];
            $poiType = $this->resolveCulturalPoiType($tags);

            if (null === $poiType) {
                continue;
            }

            $pois[] = [
                'name' => $tags['name'] ?? $poiType,
                'type' => $poiType,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'openingHours' => null,
                'estimatedPrice' => null,
                'description' => null,
                'wikidataId' => isset($tags['wikidata']) && '' !== $tags['wikidata'] ? $tags['wikidata'] : null,
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

    /**
     * @param array<string, string> $tags
     */
    private function resolveCulturalPoiType(array $tags): ?string
    {
        if (isset($tags['tourism'])) {
            return match ($tags['tourism']) {
                'museum' => 'museum',
                'attraction' => 'attraction',
                'viewpoint' => 'viewpoint',
                default => null,
            };
        }

        if (isset($tags['historic']) && \in_array($tags['historic'], self::NOTABLE_HISTORIC_VALUES, true)) {
            return $tags['historic'];
        }

        return null;
    }
}
