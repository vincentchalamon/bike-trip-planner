<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Engine\PricingHeuristicEngine;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;

final readonly class OsmAccommodationSource implements AccommodationSourceInterface
{
    public function __construct(
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private PricingHeuristicEngine $pricingEngine,
    ) {
    }

    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): array
    {
        $query = $this->queryBuilder->buildAccommodationQuery($endPoints, $radiusMeters, $enabledTypes);
        $result = $this->scanner->query($query);

        /** @var list<array{id?: int, type?: string, tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
        $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

        return $this->parseElements($elements);
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
     * @param list<array{id?: int, type?: string, tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    private function parseElements(array $elements): array
    {
        $candidates = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

            if (null === $lat || null === $lon) {
                continue;
            }

            $url = $tags['website']
                ?? $tags['contact:website']
                ?? (isset($element['id'], $element['type'])
                    ? \sprintf('https://www.openstreetmap.org/%s/%d', $element['type'], $element['id'])
                    : null);

            $type = $tags['tourism'] ?? ('shelter' === ($tags['amenity'] ?? null) ? 'shelter' : 'hotel');
            $name = $tags['name'] ?? $type;
            $tagCount = \count($tags);
            $pricing = $this->pricingEngine->estimatePrice($type, $tags);

            $candidates[] = [
                'name' => $name,
                'type' => $type,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'priceMin' => $pricing['min'],
                'priceMax' => $pricing['max'],
                'isExact' => $pricing['isExact'],
                'url' => $url,
                'tagCount' => $tagCount,
                'hasWebsite' => isset($tags['website']) || isset($tags['contact:website']),
                'tags' => $tags,
                'source' => 'osm',
                'wikidataId' => $tags['wikidata'] ?? null,
            ];
        }

        return $candidates;
    }
}
