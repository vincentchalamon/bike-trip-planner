<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `analyze_trip`. Never instantiated; it exists to publish the schema
 * ({@see ShareTripInput}).
 */
final readonly class AnalyzeTripInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip to enrich.')]
        public string $id,
    ) {
    }
}
