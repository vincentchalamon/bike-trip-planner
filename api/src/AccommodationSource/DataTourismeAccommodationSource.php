<?php

declare(strict_types=1);

namespace App\AccommodationSource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Engine\PricingHeuristicEngine;
use App\Format\WebsiteUrl;
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
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, stars: ?int, capacity: ?int, fee: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string, phone: ?string, osmType: ?string, osmId: ?int}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): array
    {
        $points = array_map(
            static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon],
            array_values($endPoints),
        );

        $candidates = [];
        foreach ($this->accommodationRepository->findNear($points, $radiusMeters, $enabledTypes) as $accommodation) {
            // No name filter here any more (#884): the completeness gate decides at import
            // time and a CHECK on tourism.accommodations enforces it, so an unnamed row
            // cannot reach this loop. See OsmAccommodationSource for the full rationale.
            $name = $accommodation['name'];

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

            // The contact block and the opening hours now live in real columns
            // (#872); `tags` remains the fallback for rows imported before them and
            // the only home of `booking_url` / `image_url`, which have no column.
            // Reading them is what lets the completeness ranking score this source
            // and SeasonalityChecker decide `possibleClosed` on a DataTourisme entry.
            $tags = $accommodation['tags'];
            // Normalised even coming from the column: the fallbacks are raw flux
            // text, and a database provisioned before #872 holds unnormalised values.
            $url = WebsiteUrl::normalize($accommodation['website'] ?? $tags['website'] ?? $tags['booking_url'] ?? null);

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
                // The Q-ID the flux publishes as `owl:sameAs`, now a column: it is
                // what lets NearbyNameDeduplicator pair this entry with its OSM twin
                // instead of relying on the name + 75 m heuristic alone.
                'wikidataId' => $accommodation['wikidata'],
                'description' => $accommodation['description'],
                // Wikidata-only columns, filled at provision time now that
                // tourism.accommodations carries a Q-ID (ADR-041). The flux photo,
                // which has no column, is the fallback.
                'imageUrl' => $accommodation['imageUrl'] ?? WebsiteUrl::normalize($tags['image_url'] ?? null),
                'wikipediaUrl' => $accommodation['wikipediaUrl'],
                'openingHours' => $accommodation['openingHours'] ?? $tags['opening_hours'] ?? null,
                // Column since #872, with the same pre-migration `tags` fallback as
                // the fields above; it stopped at the repository until now.
                'phone' => $accommodation['phone'] ?? $tags['phone'] ?? null,
                // A flux entry is identified by its DataTourisme URI, not by an OSM
                // object: there is nothing to link to on openstreetmap.org.
                'osmType' => null,
                'osmId' => null,
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
