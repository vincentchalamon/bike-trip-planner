<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `delete_trip`. Never instantiated; it exists to publish the schema
 * ({@see ShareTripInput}).
 */
final readonly class DeleteTripInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip to delete.', required: true)]
        public string $id,
        #[ApiProperty(description: 'Leave this out on the first call: the tool answers with what would be deleted, and a token. Report that to the user, and call again with the token only if they want it done.')]
        public ?string $confirmationToken = null,
    ) {
    }
}
