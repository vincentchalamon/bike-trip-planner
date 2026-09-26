<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `list_trips`. Never instantiated; it exists to publish the schema.
 *
 * All optional. Without it the tool published `id`, `computationStatus` and `isLocked` — fields
 * of the `Trip` resource — and none of the filters it actually reads.
 */
final readonly class ListTripsInput
{
    public function __construct(
        #[ApiProperty(description: 'Page number, from 1. Trips come most recently created first.')]
        public ?int $page = null,
        #[ApiProperty(description: 'Trips per page, 20 by default, 30 at most.')]
        public ?int $itemsPerPage = null,
        #[ApiProperty(description: 'Keep only the trips whose title contains this text.')]
        public ?string $title = null,
        #[ApiProperty(description: 'Keep only the trips starting on or after this date (YYYY-MM-DD).')]
        public ?string $startDate = null,
        #[ApiProperty(description: 'Keep only the trips ending on or before this date (YYYY-MM-DD).')]
        public ?string $endDate = null,
    ) {
    }
}
