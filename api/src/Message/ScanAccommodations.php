<?php

declare(strict_types=1);

namespace App\Message;

use App\Scanner\QueryBuilderInterface;

final readonly class ScanAccommodations
{
    /**
     * @param list<string> $enabledAccommodationTypes
     */
    public function __construct(
        public string $tripId,
        public int $radiusMeters = QueryBuilderInterface::DEFAULT_ACCOMMODATION_RADIUS_METERS,
        public ?int $stageIndex = null,
        public array $enabledAccommodationTypes = ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel'],
    ) {
    }
}
