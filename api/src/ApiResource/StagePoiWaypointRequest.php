<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO for adding a cultural POI as a waypoint to a stage itinerary.
 *
 * Triggers async route recalculation via RecalculateRouteSegment (ADR-017).
 */
final class StagePoiWaypointRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: -90.0, max: 90.0)]
        #[ApiProperty(description: 'POI latitude to insert as waypoint.')]
        public ?float $waypointLat = null,
        #[Assert\NotNull]
        #[Assert\Range(min: -180.0, max: 180.0)]
        #[ApiProperty(description: 'POI longitude to insert as waypoint.')]
        public ?float $waypointLon = null,
    ) {
    }
}
