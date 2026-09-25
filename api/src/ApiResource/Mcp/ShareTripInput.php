<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `share_trip`, and nothing else.
 *
 * Never instantiated. The `inputSchema` a tool publishes is the JSON Schema of its input class,
 * so leaving `input` absent would publish the OUTPUT properties of the resource as arguments —
 * for a share, `token` and `shortCode`, neither of which a caller supplies — and the URI
 * variables would not appear at all. Declaring the arguments here is the only way a model is
 * told what to send.
 *
 * The URI variable is a property like any other: the Handler copies arguments into
 * `uriVariables` by name, so one declaration serves both the schema and the addressing.
 */
final readonly class ShareTripInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip to publish, as returned by `list_trips` or `get_trip`.')]
        public string $tripId,
    ) {
    }
}
