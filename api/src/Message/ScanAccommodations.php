<?php

declare(strict_types=1);

namespace App\Message;

use App\ApiResource\AccommodationScanRequest;
use App\ApiResource\TripRequest;

final readonly class ScanAccommodations
{
    /**
     * @param list<string> $enabledAccommodationTypes
     */
    public function __construct(
        public string $tripId,
        public int $radiusMeters = AccommodationScanRequest::DEFAULT_ACCOMMODATION_RADIUS_METERS,
        public ?int $stageIndex = null,
        public array $enabledAccommodationTypes = TripRequest::ALL_ACCOMMODATION_TYPES,
        public bool $isExpandScan = false,
        public ?int $generation = null,
    ) {
    }
}
