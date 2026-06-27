<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Resolve and persist reverse-geocoded city labels for a trip's stage endpoints
 * (recette #649, #3c/#9). Dispatched after stage generation so the anonymous
 * shared view and a reloaded trip render city names instead of GPS coordinates.
 */
final readonly class ResolveStageLabels
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }
}
