<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The link an agent hands to a person, rather than the row that backs it.
 *
 * `TripShare` answers with a short code, which the web and mobile clients turn into an address
 * because they know where they are running. An agent does not, and a short code it cannot build
 * a URL from is of no use to the person it is talking to.
 *
 * This is also how the excluded downloads stay reachable: `/s/{code}.gpx` and `.fit` need no
 * token, so the agent passes the link along and the human downloads the file. The bytes never
 * go through the tool transport, which could not carry them anyway.
 */
final readonly class ShareLink
{
    public function __construct(
        #[ApiProperty(description: 'Public address of the trip. Anyone holding it can view the trip, and download it as GPX (append `.gpx`) or FIT (append `.fit`). Give this to the user; it needs no account.')]
        public string $url,
        public string $shortCode,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
