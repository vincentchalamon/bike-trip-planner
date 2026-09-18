<?php

declare(strict_types=1);

namespace App\Message;

use App\ApiResource\AccommodationScanRequest;
use App\ApiResource\TripRequest;

final readonly class ScanAccommodations
{
    /**
     * Scans accommodations for a whole trip, or for one stage when $stageId is given.
     *
     * The stage is addressed by identity: the scan runs well after the edit that asked
     * for it, by which point a position may name a different stage (ADR-066).
     *
     * @param list<string> $enabledAccommodationTypes
     */
    public function __construct(
        public string $tripId,
        public int $radiusMeters = AccommodationScanRequest::DEFAULT_ACCOMMODATION_RADIUS_METERS,
        public ?string $stageId = null,
        public array $enabledAccommodationTypes = TripRequest::ALL_ACCOMMODATION_TYPES,
        public bool $isExpandScan = false,
        public ?int $generation = null,
    ) {
    }
}
