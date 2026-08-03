<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Engine\PricingHeuristicEngine;
use App\Tourism\AccommodationRepositoryInterface;

/**
 * Accommodations from the local-first `tourism` schema (DataTourisme flux), read
 * within a radius of the stage end points. Replaces the runtime DataTourisme
 * REST API (ADR-040). When the flux carried a structured offer price it is used
 * verbatim (exact); otherwise the category heuristic estimates a range.
 */
final readonly class DataTourismeAccommodationSource implements AccommodationSourceInterface
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
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, stars: ?int, capacity: ?int, fee: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): array
    {
        $points = array_map(
            static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon],
            array_values($endPoints),
        );

        $candidates = [];
        foreach ($this->accommodationRepository->findNear($points, $radiusMeters, $enabledTypes) as $accommodation) {
            // Skip unnamed entries: an unnamed accommodation the rider cannot
            // identify is not a usable suggestion (recette).
            $name = $accommodation['name'];
            if (null === $name || '' === trim($name)) {
                continue;
            }

            if (null !== $accommodation['price']) {
                $priceMin = $accommodation['price'];
                $priceMax = $accommodation['price'];
                $isExact = true;
            } else {
                $pricing = $this->pricingEngine->estimatePrice($accommodation['category'], []);
                $priceMin = $pricing['min'];
                $priceMax = $pricing['max'];
                $isExact = $pricing['isExact'];
            }

            // The contact block and the opening hours the flux carries are preserved
            // in `tourism.accommodations.tags` by the provisioner (#871), pending the
            // dedicated columns of #872. Reading them here is what lets the
            // completeness ranking score this source and SeasonalityChecker decide
            // `possibleClosed` on a DataTourisme entry at all.
            $tags = $accommodation['tags'];
            $url = $tags['website'] ?? $tags['booking_url'] ?? null;

            // `tagCount` counts the attributes actually filled for this entry: the
            // flux publishes fields, not OSM tags, so the OSM tag-richness proxy would
            // read as 0 and penalise the curated source (#869).
            $filledAttributes = array_filter(
                [$name, $accommodation['category'], $accommodation['description'], $accommodation['capacity'], $accommodation['price']],
                static fn (string|int|float|null $value): bool => null !== $value && '' !== $value,
            );

            $candidates[] = [
                'name' => $name,
                'type' => $accommodation['category'],
                'lat' => $accommodation['lat'],
                'lon' => $accommodation['lon'],
                'priceMin' => $priceMin,
                'priceMax' => $priceMax,
                'isExact' => $isExact,
                'url' => $url,
                // The flux carries no star rating and no fee flag; capacity
                // (allowedPersons) is a real column and feeds the ranking.
                'stars' => null,
                'capacity' => $accommodation['capacity'],
                'fee' => null,
                'tagCount' => \count($filledAttributes),
                'hasWebsite' => null !== $url,
                'tags' => $tags,
                'source' => 'datatourisme',
                'wikidataId' => null,
                'description' => $accommodation['description'],
                // tourism.accommodations carries no `wikidata` Q-ID column, so it is
                // not Wikidata-enriched at provision time (ADR-041 enriches only
                // tourism.cultural_pois / food_pois): these stay null by design.
                'imageUrl' => null,
                'wikipediaUrl' => null,
                'openingHours' => $tags['opening_hours'] ?? null,
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
        return 'datatourisme';
    }
}
