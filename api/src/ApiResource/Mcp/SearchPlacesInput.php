<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The arguments of `search_places`. Never instantiated; it exists to publish the schema.
 *
 * Without it the tool published the fields of its own answer — a name, coordinates, an address
 * — as arguments, and never mentioned `q`, which its description told the model to pass.
 */
final readonly class SearchPlacesInput
{
    public function __construct(
        #[ApiProperty(description: 'What to look for: a town, a pass, a landmark.', required: true)]
        public string $q,
        #[ApiProperty(description: 'How many places to return, 1 to 10. Defaults to 5.')]
        public ?int $limit = null,
    ) {
    }
}
