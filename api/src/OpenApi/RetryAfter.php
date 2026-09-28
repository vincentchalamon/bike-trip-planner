<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * The header every rate-limited operation answers its 429 with, for the `openapi` responses.
 * A constant rather than a factory because it is used inside attributes.
 */
final class RetryAfter
{
    public const array HEADERS = [
        'Retry-After' => [
            'description' => 'Seconds to wait before the limit lets the call through again.',
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ],
    ];
}
