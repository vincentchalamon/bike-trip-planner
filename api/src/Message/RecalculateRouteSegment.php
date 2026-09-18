<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Recomputes one stage's geometry around an inserted waypoint.
 *
 * Addresses the stage by identity, not by position: the message is consumed after any
 * number of intervening edits, and a stale index would rewrite the geometry of a
 * different stage — a silent corruption rather than a lost write (ADR-066).
 */
final readonly class RecalculateRouteSegment
{
    public function __construct(
        public string $tripId,
        public string $stageId,
        public float $waypointLat,
        public float $waypointLon,
        public string $reason,
        public ?int $generation = null,
    ) {
    }
}
