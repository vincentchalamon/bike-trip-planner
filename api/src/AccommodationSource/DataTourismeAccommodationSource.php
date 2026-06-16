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
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes = TripRequest::ALL_ACCOMMODATION_TYPES): array
    {
        $points = array_map(
            static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon],
            array_values($endPoints),
        );

        $candidates = [];
        foreach ($this->accommodationRepository->findNear($points, $radiusMeters, $enabledTypes) as $accommodation) {
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

            $candidates[] = [
                'name' => $accommodation['name'] ?? $accommodation['category'],
                'type' => $accommodation['category'],
                'lat' => $accommodation['lat'],
                'lon' => $accommodation['lon'],
                'priceMin' => $priceMin,
                'priceMax' => $priceMax,
                'isExact' => $isExact,
                'url' => null,
                'tagCount' => 0,
                'hasWebsite' => false,
                'tags' => [],
                'source' => 'datatourisme',
                'wikidataId' => null,
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
