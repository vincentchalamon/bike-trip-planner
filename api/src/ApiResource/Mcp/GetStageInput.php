<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `get_stage`. Never instantiated; it exists to publish the schema.
 *
 * Without it the tool published the whole `Stage` resource as arguments — the geometry, the
 * alerts, the accommodations — and never mentioned `stageId`, the one argument it cannot work
 * without.
 */
final readonly class GetStageInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip the day belongs to.', required: true)]
        public string $tripId,
        #[ApiProperty(description: 'Identifier of the day, as listed in `stages` by `get_trip`.', required: true)]
        public string $stageId,
    ) {
    }
}
