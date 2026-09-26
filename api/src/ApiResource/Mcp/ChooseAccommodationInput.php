<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\StageSelectAccommodationRequest;

/**
 * The arguments of `choose_accommodation`, and nothing else.
 *
 * Never instantiated: it publishes the `inputSchema` ({@see ShareTripInput}). The record is
 * {@see StageSelectAccommodationRequest}, named by `mcp_input`.
 *
 * The place is addressed by its coordinates rather than by an identifier, because it has none
 * that is stable: an OpenStreetMap entry is keyed by an (osmType, osmId) pair that a
 * DataTourisme entry does not have, and a manually-added one has neither. The coordinates are
 * what `get_stage` publishes for every option it lists, and they are what the HTTP operation
 * takes too — there is no second addressing scheme to keep in step.
 */
final readonly class ChooseAccommodationInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip.', required: true)]
        public string $tripId,
        #[ApiProperty(description: 'Identifier of the day to sleep on, as published by `get_trip`.', required: true)]
        public string $stageId,
        #[ApiProperty(description: 'The trip version this edit is conditional on, from `get_trip` or from the previous edit. Choosing a place moves the end of the day, so the trip moves with it.', required: true)]
        public int $version,
        #[ApiProperty(description: 'Latitude of the chosen place, copied from the `accommodations` list `get_stage` publishes for that day. Leave both coordinates out to un-choose whatever is currently selected.')]
        public ?float $selectedAccommodationLat = null,
        #[ApiProperty(description: 'Longitude of the chosen place.')]
        public ?float $selectedAccommodationLon = null,
    ) {
    }
}
