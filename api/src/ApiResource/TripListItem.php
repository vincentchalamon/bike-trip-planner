<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Lightweight read-only DTO for the trip collection list endpoint.
 *
 * Exposes only the fields needed to render a trip summary row: identity,
 * dates, distance/stage counts, title, and computed status.
 * Full trip data (stages, computation status…) is fetched separately on the detail page.
 *
 * Status values:
 *   - "draft"     : trip has no computed stages yet (no analysis run)
 *   - "analyzing" : analysis is currently in progress (computations pending/running)
 *   - "analyzed"  : all computations are done or failed (full results available)
 */
final readonly class TripListItem
{
    public function __construct(
        public string $id,
        public ?string $title,
        public ?\DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public float $totalDistance,
        public int $stageCount,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        #[ApiProperty(description: 'Computed trip status: draft | analyzing | analyzed')]
        public string $status = 'draft',
    ) {
    }
}
