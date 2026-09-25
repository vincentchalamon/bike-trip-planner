<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `unshare_trip`. Never instantiated; it exists to publish the schema
 * ({@see ShareTripInput}).
 */
final readonly class UnshareTripInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip whose public link should be revoked.')]
        public string $tripId,
        #[ApiProperty(description: 'Leave this out on the first call: the tool answers with what revoking would affect, and a token. Call it again with that token to carry it out.')]
        public ?string $confirmationToken = null,
    ) {
    }
}
