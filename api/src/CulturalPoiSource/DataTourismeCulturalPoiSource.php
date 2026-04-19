<?php

declare(strict_types=1);

namespace App\CulturalPoiSource;

use App\DataTourisme\DataTourismeClientInterface;

final readonly class DataTourismeCulturalPoiSource implements CulturalPoiSourceInterface
{
    /**
     * DataTourisme ontology types that qualify as cultural POIs.
     *
     * @var list<string>
     */
    private const array CULTURAL_ONTOLOGY_TYPES = [
        'schema:Museum',
        'schema:TouristAttraction',
        'schema:Landmark',
        'urn:resource:CulturalSite',
        'urn:resource:NaturalHeritage',
    ];

    public function __construct(
        private DataTourismeClientInterface $client,
    ) {
    }

    /**
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string}>
     */
    public function fetchForStages(array $stageGeometries, int $radiusMeters): array
    {
        if (!$this->client->isEnabled()) {
            return [];
        }

        [$minLat, $minLon, $maxLat, $maxLon] = $this->buildBbox($stageGeometries, $radiusMeters);

        $response = $this->client->request('/api/v1/places', [
            'filters[0][path]' => '@type',
            'filters[0][operator]' => 'in',
            'filters[0][value]' => implode(',', self::CULTURAL_ONTOLOGY_TYPES),
            'filters[1][path]' => 'hasGeometry.latitude',
            'filters[1][operator]' => 'gte',
            'filters[1][value]' => $minLat,
            'filters[2][path]' => 'hasGeometry.latitude',
            'filters[2][operator]' => 'lte',
            'filters[2][value]' => $maxLat,
            'filters[3][path]' => 'hasGeometry.longitude',
            'filters[3][operator]' => 'gte',
            'filters[3][value]' => $minLon,
            'filters[4][path]' => 'hasGeometry.longitude',
            'filters[4][operator]' => 'lte',
            'filters[4][value]' => $maxLon,
        ]);

        /** @var list<array<string, mixed>> $results */
        $results = \is_array($response['results'] ?? null) ? $response['results'] : (
            \is_array($response['member'] ?? null) ? $response['member'] : []
        );

        $pois = [];
        foreach ($results as $item) {
            $poi = $this->mapItem($item);
            if (null !== $poi) {
                $pois[] = $poi;
            }
        }

        return $pois;
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
     * @param list<list<array{lat: float, lon: float}>> $stageGeometries
     *
     * @return array{float, float, float, float}
     */
    private function buildBbox(array $stageGeometries, int $radiusMeters): array
    {
        $allLats = [];
        $allLons = [];

        foreach ($stageGeometries as $geometry) {
            foreach ($geometry as $point) {
                $allLats[] = $point['lat'];
                $allLons[] = $point['lon'];
            }
        }

        if ([] === $allLats || [] === $allLons) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $avgLat = (min($allLats) + max($allLats)) / 2.0;
        $latOffset = $radiusMeters / 111_000.0;
        $lonOffset = $radiusMeters / (111_000.0 * max(cos(deg2rad($avgLat)), 0.001));

        return [
            min($allLats) - $latOffset,
            min($allLons) - $lonOffset,
            max($allLats) + $latOffset,
            max($allLons) + $lonOffset,
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{name: string, type: string, lat: float, lon: float, openingHours: string|null, estimatedPrice: float|null, description: string|null, wikidataId: string|null, source: string}|null
     */
    private function mapItem(array $item): ?array
    {
        $name = $this->extractLabel($item['rdfs:label'] ?? null);
        if (null === $name || '' === $name) {
            return null;
        }

        $coords = $this->extractCoordinates($item);
        if (null === $coords) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $this->resolveType($item['@type'] ?? []),
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
            'openingHours' => $this->extractOpeningHours($item['openingHoursSpecification'] ?? null),
            'estimatedPrice' => $this->extractPrice($item['offers'] ?? null),
            'description' => $this->extractDescription($item),
            'wikidataId' => $this->extractWikidataId($item['owl:sameAs'] ?? null),
            'source' => 'datatourisme',
        ];
    }

    private function extractLabel(mixed $label): ?string
    {
        if (\is_string($label)) {
            return $label;
        }

        if (\is_array($label)) {
            foreach ($label as $entry) {
                if (\is_array($entry) && isset($entry['@value']) && \is_scalar($entry['@value'])) {
                    return (string) $entry['@value'];
                }

                if (\is_string($entry)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{lat: float, lon: float}|null
     */
    private function extractCoordinates(array $item): ?array
    {
        $geometry = $item['hasGeometry'] ?? $item['schema:geo'] ?? null;
        if (!\is_array($geometry)) {
            return null;
        }

        $lat = $geometry['schema:latitude'] ?? $geometry['lat'] ?? null;
        $lon = $geometry['schema:longitude'] ?? $geometry['lon'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lon' => (float) $lon];
    }

    private function resolveType(mixed $types): string
    {
        if (\is_string($types)) {
            $types = [$types];
        }

        if (!\is_array($types)) {
            return 'attraction';
        }

        foreach ($types as $type) {
            $resolved = match ($type) {
                'schema:Museum', 'urn:resource:Museum' => 'museum',
                'schema:Landmark', 'urn:resource:Monument', 'urn:resource:CulturalSite' => 'monument',
                'urn:resource:NaturalHeritage' => 'viewpoint',
                default => null,
            };

            if (null !== $resolved) {
                return $resolved;
            }
        }

        return 'attraction';
    }

    private function extractOpeningHours(mixed $specs): ?string
    {
        if (!\is_array($specs)) {
            return null;
        }

        if (isset($specs['schema:opens'])) {
            $specs = [$specs];
        }

        $parts = [];
        foreach ($specs as $spec) {
            if (!\is_array($spec)) {
                continue;
            }

            $days = $spec['schema:dayOfWeek'] ?? null;
            $opens = $spec['schema:opens'] ?? null;
            $closes = $spec['schema:closes'] ?? null;

            if (!\is_string($opens) || !\is_string($closes)) {
                continue;
            }

            if (\is_array($days)) {
                $dayParts = [];
                foreach ($days as $day) {
                    if (\is_string($day)) {
                        $dayParts[] = $this->formatDay($day);
                    }
                }

                $dayStr = implode(', ', $dayParts);
            } else {
                $dayStr = \is_string($days) ? $days : '';
            }

            $parts[] = trim(\sprintf('%s %s–%s', $dayStr, $opens, $closes));
        }

        return [] === $parts ? null : implode(' | ', $parts);
    }

    private function formatDay(string $day): string
    {
        $map = [
            'schema:Monday' => 'Mon',
            'schema:Tuesday' => 'Tue',
            'schema:Wednesday' => 'Wed',
            'schema:Thursday' => 'Thu',
            'schema:Friday' => 'Fri',
            'schema:Saturday' => 'Sat',
            'schema:Sunday' => 'Sun',
        ];

        return $map[$day] ?? $day;
    }

    private function extractPrice(mixed $offers): ?float
    {
        if (!\is_array($offers)) {
            return null;
        }

        if (isset($offers['priceSpecification'])) {
            $offers = [$offers];
        }

        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }

            $priceSpec = $offer['priceSpecification'] ?? null;
            if (!\is_array($priceSpec)) {
                continue;
            }

            if (isset($priceSpec['schema:price'])) {
                $priceSpec = [$priceSpec];
            }

            foreach ($priceSpec as $spec) {
                if (!\is_array($spec)) {
                    continue;
                }

                $price = $spec['schema:price'] ?? $spec['price'] ?? null;
                $currency = $spec['schema:priceCurrency'] ?? $spec['priceCurrency'] ?? null;

                if (is_numeric($price) && (null === $currency || 'EUR' === $currency)) {
                    return (float) $price;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractDescription(array $item): ?string
    {
        $raw = $item['rdfs:comment'] ?? $item['shortDescription'] ?? $item['schema:description'] ?? null;

        if (\is_string($raw)) {
            return $raw;
        }

        if (\is_array($raw)) {
            foreach ($raw as $entry) {
                if (\is_array($entry) && isset($entry['@value']) && \is_scalar($entry['@value'])) {
                    return (string) $entry['@value'];
                }

                if (\is_string($entry)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    private function extractWikidataId(mixed $sameAs): ?string
    {
        if (\is_string($sameAs)) {
            $sameAs = [$sameAs];
        }

        if (!\is_array($sameAs)) {
            return null;
        }

        foreach ($sameAs as $uri) {
            if (\is_string($uri) && str_contains($uri, 'wikidata.org/entity/')) {
                $parts = explode('/', $uri);

                return end($parts) ?: null;
            }
        }

        return null;
    }
}
