<?php

declare(strict_types=1);

namespace App\ApiResource;

use DateTimeImmutable;

/**
 * Lightweight read-only DTO for the trip collection list endpoint.
 *
 * Exposes only the fields needed to render a trip summary row: identity,
 * dates, distance/stage counts, and the title. Full trip data (stages,
 * computation status…) is fetched separately on the detail page.
 */
final readonly class TripListItem
{
    public function __construct(
        public string $id,
        public ?string $title,
        public ?DateTimeImmutable $startDate,
        public ?DateTimeImmutable $endDate,
        public float $totalDistance,
        public int $stageCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
