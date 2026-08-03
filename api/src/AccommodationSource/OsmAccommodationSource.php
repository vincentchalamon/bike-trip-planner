<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Engine\PricingHeuristicEngine;
use App\Format\OsmContactTags;
use App\Format\WebsiteUrl;
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
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, stars: ?int, capacity: ?int, fee: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string, phone: ?string, osmType: ?string, osmId: ?int}>
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
            // stars / fee are indexed columns, not just raw tags: the heuristic
            // uses them (ADR-040 §45), the ranking scores them (#869).
            $pricing = $this->pricingEngine->estimatePrice(
                $accommodation['category'],
                $tags,
                $accommodation['stars'],
                $accommodation['fee'],
            );

            // The indexed `website` column is the projection of the same contact
            // block, so it comes first; the tag cascade (website, contact:website,
            // url, contact:url) covers the spellings the column misses and the rows
            // indexed before it was widened. Both go through WebsiteUrl, so a
            // schema-less "www.gite.fr" is served absolute and free text is dropped.
            $url = WebsiteUrl::normalize($accommodation['website']) ?? OsmContactTags::website($tags);

            $candidates[] = [
                'name' => $name,
                'type' => $accommodation['category'],
                'lat' => $accommodation['lat'],
                'lon' => $accommodation['lon'],
                'priceMin' => $pricing['min'],
                'priceMax' => $pricing['max'],
                'isExact' => $pricing['isExact'],
                'url' => $url,
                'stars' => $accommodation['stars'],
                'capacity' => $accommodation['capacity'],
                'fee' => $accommodation['fee'],
                'tagCount' => \count($tags),
                'hasWebsite' => null !== $url,
                'tags' => $tags,
                'source' => 'osm',
                'wikidataId' => $accommodation['wikidata'],
                'description' => $accommodation['description'],
                'imageUrl' => $accommodation['imageUrl'],
                'wikipediaUrl' => $accommodation['wikipediaUrl'],
                'openingHours' => $accommodation['openingHours'],
                'phone' => OsmContactTags::phone($tags),
                'osmType' => $accommodation['osmType'],
                'osmId' => $accommodation['osmId'],
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
