<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\TripRequest;

/**
 * The arguments of `update_trip_settings`, and nothing else.
 *
 * Never instantiated: it publishes the `inputSchema` ({@see ShareTripInput}). The record the
 * arguments are merged into is {@see TripRequest}, named by `mcp_input`.
 *
 * Every setting is optional, and that is the contract: what is not sent is not touched. A model
 * asked to raise the daily distance sends one argument, not a full copy of the trip it would
 * have to fetch first and could get wrong.
 *
 * `sourceUrl` is deliberately absent. Re-pointing an existing trip at another route is not a
 * settings change — it is a different trip, and `create_trip` is how you make one.
 */
final readonly class UpdateTripSettingsInput
{
    /**
     * @param list<string> $enabledAccommodationTypes
     */
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip to change.', required: true)]
        public string $id,
        #[ApiProperty(description: 'The trip version this edit is conditional on, as returned by `get_trip` or by the previous edit. The edit is refused if the trip moved on since — read it again and reapply. Required: there is no way to say "whatever the current state is".', required: true)]
        public int $version,
        #[ApiProperty(description: 'Leave this out on the first call. The answer will describe what the change costs and hand back a token; call again with that token, and the same arguments, to apply it.')]
        public ?string $confirmationToken = null,
        #[ApiProperty(description: 'New name for the trip.')]
        public ?string $title = null,
        #[ApiProperty(description: 'New departure date, RFC 3339 (e.g. 2026-07-01T00:00:00+00:00).')]
        public ?string $startDate = null,
        #[ApiProperty(description: 'New last day, RFC 3339. This is how you ask for the trip to be spread over more or fewer days.')]
        public ?string $endDate = null,
        #[ApiProperty(description: 'How much shorter each day gets than the one before, between 0.5 and 1.0.')]
        public ?float $fatigueFactor = null,
        #[ApiProperty(description: 'How much climbing shortens a day: kilometres removed per this many metres of ascent.')]
        public ?float $elevationPenalty = null,
        #[ApiProperty(description: 'Whether the rider is on an e-bike.')]
        public ?bool $ebikeMode = null,
        #[ApiProperty(description: 'Usual departure hour, 0 to 23.')]
        public ?int $departureHour = null,
        #[ApiProperty(description: 'Hard cap on a day, in kilometres, between 30 and 300.')]
        public ?float $maxDistancePerDay = null,
        #[ApiProperty(description: 'Average riding speed in km/h, between 5 and 50.')]
        public ?float $averageSpeed = null,
        #[ApiProperty(description: 'Kinds of place to look for to sleep in. Any of: camp_site, hostel, alpine_hut, chalet, guest_house, hotel, wilderness_hut. At least one.')]
        public ?array $enabledAccommodationTypes = null,
    ) {
    }
}
