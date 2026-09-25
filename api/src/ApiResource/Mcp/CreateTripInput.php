<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\TripRequest;

/**
 * The arguments of `create_trip`, and nothing else.
 *
 * Never instantiated: it publishes the `inputSchema` ({@see ShareTripInput}). What the arguments
 * are actually denormalized into is {@see TripRequest}, named by the `mcp_input` extra property,
 * and {@see \App\Tests\Integration\Mcp\McpToolContractTest} checks that every property below
 * exists and is writable there — a published argument the record cannot accept is taken, lost,
 * and sent again forever.
 *
 * There is no `sourceFile` and there will not be: a `tools/call` carries one JSON document, so a
 * 30 MB multipart upload has no channel here. Creation from a source URL is the whole surface,
 * and that is a deliberate restriction rather than an omission (ADR-080).
 */
final readonly class CreateTripInput
{
    /**
     * @param list<string> $enabledAccommodationTypes
     */
    public function __construct(
        #[ApiProperty(description: 'Public URL of the route to plan from. Komoot tour or collection, Strava route, or RideWithGPS route; https only. This is the only way to create a trip: a file cannot be sent through this transport.')]
        public string $sourceUrl,
        #[ApiProperty(description: 'Name for the trip. Optional: when the source has a title of its own, it is used.')]
        public ?string $title = null,
        #[ApiProperty(description: 'Departure date, RFC 3339 (e.g. 2026-07-01T00:00:00+00:00). Optional, and needed for anything date-dependent: weather, opening days, events.')]
        public ?string $startDate = null,
        #[ApiProperty(description: 'Last day of the trip, RFC 3339. Optional: without it the number of days is derived from the distance. Setting it is how you ask for a trip of a given length.')]
        public ?string $endDate = null,
        #[ApiProperty(description: 'How much shorter each day gets than the one before, as a factor between 0.5 and 1.0. 0.9 means -10% per day; 1.0 means every day the same length.')]
        public ?float $fatigueFactor = null,
        #[ApiProperty(description: 'How much climbing shortens a day: kilometres removed per this many metres of ascent. Default 50.')]
        public ?float $elevationPenalty = null,
        #[ApiProperty(description: 'Whether the rider is on an e-bike, which changes pacing and adds charging points to what is looked for.')]
        public ?bool $ebikeMode = null,
        #[ApiProperty(description: 'Usual departure hour, 0 to 23. Default 8. Used to place the day against opening hours and weather.')]
        public ?int $departureHour = null,
        #[ApiProperty(description: 'Hard cap on a day, in kilometres, between 30 and 300. Default 80. This is the setting to change when the user says how far they want to ride per day.')]
        public ?float $maxDistancePerDay = null,
        #[ApiProperty(description: 'Average riding speed in km/h, between 5 and 50. Default 15.')]
        public ?float $averageSpeed = null,
        #[ApiProperty(description: 'Kinds of place to look for to sleep in. Any of: camp_site, hostel, alpine_hut, chalet, guest_house, hotel, wilderness_hut. At least one. Defaults to all of them.')]
        public ?array $enabledAccommodationTypes = null,
        #[ApiProperty(description: 'Optional. Leave it out unless you have a reason: two identical calls a few minutes apart are already treated as one, and answer with the same trip. Pass a string of your own only to make two deliberately identical trips distinct.')]
        public ?string $idempotencyKey = null,
    ) {
    }
}
