<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * What a write tool answers when there is no resource to answer with.
 *
 * Several processors of the HTTP pipeline return a `Response` or nothing at all — perfectly
 * sensible for a transport whose status code says what happened, and meaningless here: a
 * `tools/call` carries one JSON document and a Symfony `Response` normalised into it would say
 * nothing a model can act on.
 *
 * The version is on it because of the loop this transport imposes. An edit must state the
 * version it is conditional on; if each edit ended by saying nothing, an agent restructuring
 * five days would have to re-read the trip between every one of them — ten calls instead of
 * five, on the loop that is already the likeliest source of load on this system.
 */
final readonly class WriteAcknowledgement
{
    public function __construct(
        #[ApiProperty(description: 'What was done. Report it to the user.')]
        public string $result,
        #[ApiProperty(description: 'The trip version after this change. Pass it as `version` on the next edit of this trip; there is no need to read the trip again in between.')]
        public ?int $version = null,
        #[ApiProperty(description: 'What to do next, when there is something to do.')]
        public ?string $nextAction = null,
    ) {
    }
}
