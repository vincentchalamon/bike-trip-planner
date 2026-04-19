<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\DataTourisme\DataTourismeClientInterface;
use App\Engine\PricingHeuristicEngine;

final readonly class DataTourismeAccommodationSource implements AccommodationSourceInterface
{
    private const array ACCOMMODATION_CLASSES = [
        'schema:Campground',
        'schema:Hostel',
        'schema:Hotel',
        'schema:LodgingBusiness',
        'urn:resource:CampingLocation',
    ];

    private const array CLASS_TO_TYPE = [
        'schema:Campground' => 'camp_site',
        'urn:resource:CampingLocation' => 'camp_site',
        'schema:Hostel' => 'hostel',
        'schema:Hotel' => 'hotel',
        'schema:LodgingBusiness' => 'hotel',
    ];

    public function __construct(
        private DataTourismeClientInterface $client,
        private PricingHeuristicEngine $pricingEngine,
    ) {
    }

    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes): array
    {
        if ([] === $endPoints) {
            return [];
        }

        $bbox = $this->buildBbox($endPoints, $radiusMeters);

        $result = $this->client->request('/api/v1/places', [
            'filters[0][path]' => '@type',
            'filters[0][operator]' => 'in',
            'filters[0][value]' => implode(',', self::ACCOMMODATION_CLASSES),
            'filters[1][path]' => 'hasGeometry.longitude',
            'filters[1][operator]' => 'gte',
            'filters[1][value]' => $bbox['lonMin'],
            'filters[2][path]' => 'hasGeometry.longitude',
            'filters[2][operator]' => 'lte',
            'filters[2][value]' => $bbox['lonMax'],
            'filters[3][path]' => 'hasGeometry.latitude',
            'filters[3][operator]' => 'gte',
            'filters[3][value]' => $bbox['latMin'],
            'filters[4][path]' => 'hasGeometry.latitude',
            'filters[4][operator]' => 'lte',
            'filters[4][value]' => $bbox['latMax'],
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = \is_array($result['results'] ?? null) ? $result['results'] : [];

        return $this->mapItems($items, $enabledTypes);
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function getName(): string
    {
        return 'datatourisme';
    }

    /**
     * @param array<int, Coordinate> $endPoints
     *
     * @return array{latMin: float, latMax: float, lonMin: float, lonMax: float}
     */
    private function buildBbox(array $endPoints, int $radiusMeters): array
    {
        $lats = array_map(static fn (Coordinate $c): float => $c->lat, $endPoints);
        $lons = array_map(static fn (Coordinate $c): float => $c->lon, $endPoints);

        $midLat = (\count($endPoints) > 0)
            ? (array_sum(array_map(static fn (Coordinate $c): float => $c->lat, $endPoints)) / \count($endPoints))
            : 0.0;
        $latDegreeOffset = $radiusMeters / 111_000.0;
        $lonDegreeOffset = $radiusMeters / (111_000.0 * max(cos(deg2rad($midLat)), 0.001));

        return [
            'latMin' => min($lats) - $latDegreeOffset,
            'latMax' => max($lats) + $latDegreeOffset,
            'lonMin' => min($lons) - $lonDegreeOffset,
            'lonMax' => max($lons) + $lonDegreeOffset,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>               $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    private function mapItems(array $items, array $enabledTypes): array
    {
        $candidates = [];

        foreach ($items as $item) {
            $geometry = \is_array($item['hasGeometry'] ?? null) ? $item['hasGeometry'] : null;
            $lat = \is_float($geometry['latitude'] ?? null) || \is_int($geometry['latitude'] ?? null)
                ? (float) $geometry['latitude']
                : null;
            $lon = \is_float($geometry['longitude'] ?? null) || \is_int($geometry['longitude'] ?? null)
                ? (float) $geometry['longitude']
                : null;

            if (null === $lat || null === $lon) {
                continue;
            }

            $type = $this->resolveType($item);

            if ([] !== $enabledTypes && !\in_array($type, $enabledTypes, true)) {
                continue;
            }

            $name = $this->resolveName($item) ?? $type;
            $url = $this->resolveUrl($item);
            $pricing = $this->resolvePricing($item, $type);
            $wikidataId = $this->resolveWikidataId($item);

            $candidates[] = [
                'name' => $name,
                'type' => $type,
                'lat' => $lat,
                'lon' => $lon,
                'priceMin' => $pricing['min'],
                'priceMax' => $pricing['max'],
                'isExact' => $pricing['isExact'],
                'url' => $url,
                'tagCount' => 0,
                'hasWebsite' => null !== $url,
                'tags' => [],
                'source' => 'datatourisme',
                'wikidataId' => $wikidataId,
            ];
        }

        return $candidates;
    }

    /** @param array<string, mixed> $item */
    private function resolveType(array $item): string
    {
        $types = \is_array($item['@type'] ?? null) ? $item['@type'] : [$item['@type'] ?? ''];

        foreach (self::CLASS_TO_TYPE as $class => $type) {
            if (\in_array($class, $types, true)) {
                return $type;
            }
        }

        return 'hotel';
    }

    /** @param array<string, mixed> $item */
    private function resolveName(array $item): ?string
    {
        $label = $item['rdfs:label'] ?? null;

        if (\is_string($label) && '' !== $label) {
            return $label;
        }

        if (\is_array($label)) {
            foreach ($label as $value) {
                if (\is_string($value) && '' !== $value) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function resolveUrl(array $item): ?string
    {
        $homepage = $item['foaf:homepage'] ?? null;
        $candidates = \is_array($homepage) ? $homepage : (\is_string($homepage) ? [$homepage] : []);
        foreach ($candidates as $value) {
            if (\is_string($value) && preg_match('#^https?://#i', $value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{min: float, max: float, isExact: bool}
     */
    private function resolvePricing(array $item, string $type): array
    {
        $offers = \is_array($item['offers'] ?? null) ? $item['offers'] : [];

        $mins = [];
        $maxs = [];

        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }

            $specs = \is_array($offer['priceSpecification'] ?? null) ? $offer['priceSpecification'] : [];

            foreach ($specs as $spec) {
                if (!\is_array($spec)) {
                    continue;
                }

                if (isset($spec['minPrice']) && is_numeric($spec['minPrice'])) {
                    $mins[] = (float) $spec['minPrice'];
                }

                if (isset($spec['maxPrice']) && is_numeric($spec['maxPrice'])) {
                    $maxs[] = (float) $spec['maxPrice'];
                }

                if (isset($spec['price']) && is_numeric($spec['price'])) {
                    $mins[] = (float) $spec['price'];
                    $maxs[] = (float) $spec['price'];
                }
            }
        }

        if ([] !== $mins && [] !== $maxs) {
            return ['min' => min($mins), 'max' => max($maxs), 'isExact' => true];
        }

        $heuristic = $this->pricingEngine->estimatePrice($type);

        return ['min' => $heuristic['min'], 'max' => $heuristic['max'], 'isExact' => false];
    }

    /** @param array<string, mixed> $item */
    private function resolveWikidataId(array $item): ?string
    {
        $sameAs = $item['owl:sameAs'] ?? null;

        $candidates = \is_array($sameAs) ? $sameAs : (\is_string($sameAs) ? [$sameAs] : []);

        foreach ($candidates as $uri) {
            if (\is_string($uri) && str_contains($uri, 'wikidata.org')) {
                $parts = explode('/', rtrim($uri, '/'));

                return end($parts) ?: null;
            }
        }

        return null;
    }
}
