<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `get_trip`. Never instantiated; it exists to publish the schema.
 *
 * Without it the tool published the fields of `TripDetail` — a title, a status, the stages — as
 * arguments to fill in, and nothing marked `id` as the one it needs.
 */
final readonly class GetTripInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip, as returned by `list_trips` or `create_trip`.', required: true)]
        public string $id,
    ) {
    }
}
