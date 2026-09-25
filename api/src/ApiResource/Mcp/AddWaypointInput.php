<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\StagePoiWaypointRequest;

/**
 * The arguments of `add_waypoint`, and nothing else.
 *
 * Never instantiated: it publishes the `inputSchema` ({@see ShareTripInput}). The record is
 * {@see StagePoiWaypointRequest}, named by `mcp_input`.
 *
 * No `version`, and that is not an oversight. The HTTP operation this stands for asks for no
 * `If-Match` either: rerouting a day does not move the trip's structural version, and the day is
 * addressed by identity, so a caller working from a list that has moved on still reroutes the
 * day it meant to.
 */
final readonly class AddWaypointInput
{
    public function __construct(
        #[ApiProperty(description: 'Identifier of the trip.')]
        public string $tripId,
        #[ApiProperty(description: 'Identifier of the day to reroute, as published by `get_trip`.')]
        public string $stageId,
        #[ApiProperty(description: 'Latitude of the place to route through, from the points of interest `get_stage` publishes for that day.')]
        public float $waypointLat,
        #[ApiProperty(description: 'Longitude of the place to route through.')]
        public float $waypointLon,
    ) {
    }
}
