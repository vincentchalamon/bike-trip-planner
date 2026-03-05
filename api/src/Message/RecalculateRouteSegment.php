<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RecalculateRouteSegment
{
    public function __construct(
        public string $tripId,
        public int $stageIndex,
        public float $waypointLat,
        public float $waypointLon,
        public string $reason,
    ) {
    }
}
