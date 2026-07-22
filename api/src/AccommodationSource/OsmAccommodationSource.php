<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Engine\PricingHeuristicEngine;
use App\Osm\AccommodationRepositoryInterface;

final readonly class OsmAccommodationSource implements AccommodationSourceInterface
{
    public function __construct(
        private AccommodationRepositoryInterface $accommodationRepository,
        private PricingHeuristicEngine $pricingEngine,
    ) {
    }

    /**
     * @param array<int, Coordinate> $endPoints
     * @param list<string>           $enabledTypes
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): array
    {
        // Read accommodations from the local-first index within the radius of the
        // stage end points, where the rider sleeps (ADR-040). description /
        // imageUrl / wikipediaUrl are enriched from Wikidata at provision time.
        $points = array_map(
            static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon],
            array_values($endPoints),
        );

        $candidates = [];
        foreach ($this->accommodationRepository->findNear($points, $radiusMeters, $enabledTypes) as $accommodation) {
            // Skip unnamed entries: a nameless "shelter" surfaced as its raw OSM
            // category ("shelter", labelled "Autre" in the UI) is meaningless to
            // the rider, who cannot tell such candidates apart (recette).
            $name = $accommodation['name'];
            if (null === $name || '' === trim($name)) {
                continue;
            }

            $tags = $accommodation['tags'];
            $pricing = $this->pricingEngine->estimatePrice($accommodation['category'], $tags);

            $candidates[] = [
                'name' => $name,
                'type' => $accommodation['category'],
                'lat' => $accommodation['lat'],
                'lon' => $accommodation['lon'],
                'priceMin' => $pricing['min'],
                'priceMax' => $pricing['max'],
                'isExact' => $pricing['isExact'],
                'url' => $accommodation['website'] ?? ($tags['contact:website'] ?? null),
                'tagCount' => \count($tags),
                'hasWebsite' => null !== $accommodation['website'] || isset($tags['contact:website']),
                'tags' => $tags,
                'source' => 'osm',
                'wikidataId' => $accommodation['wikidata'],
                'description' => $accommodation['description'],
                'imageUrl' => $accommodation['imageUrl'],
                'wikipediaUrl' => $accommodation['wikipediaUrl'],
                'openingHours' => $accommodation['openingHours'],
            ];
        }

        return $candidates;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'osm';
    }
}
