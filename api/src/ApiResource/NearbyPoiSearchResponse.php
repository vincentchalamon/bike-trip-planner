<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\ApiResource\Model\PoiSuggestionDto;
use App\InRide\InRidePoiCategory;

/**
 * Output DTO for `POST /trips/{id}/nearby-pois`: the ranked POI suggestions plus
 * the search envelope ({@see \App\InRide\NearbyPoiFinder}).
 */
final readonly class NearbyPoiSearchResponse
{
    /**
     * @param list<PoiSuggestionDto> $pois
     */
    public function __construct(
        public string $tripId,
        public InRidePoiCategory $category,
        // The radius effectively applied, after clamp to [1000, 20000].
        public int $radiusMeters,
        public int $totalFound,
        public bool $capReached,
        public bool $outOfCoverage,
        public array $pois,
    ) {
    }
}
