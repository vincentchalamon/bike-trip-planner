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
 * Status values, derived by {@see \App\State\TripCollectionProvider::computeStatus()}:
 *   - "draft"     : nothing computed yet, or nothing succeeded and no stage was persisted
 *   - "analyzing" : analysis is currently in progress (computations pending/running)
 *   - "analyzed"  : results are available
 *   - "failed"    : every computation failed, but stages from an earlier run remain
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
        #[ApiProperty(
            description: 'Computed trip status. `failed` means every enrichment that settled did so in failure — a partial failure still reads `analyzed`, since the trip is usable (ADR-072).',
            schema: ['type' => 'string', 'enum' => ['draft', 'analyzing', 'analyzed', 'failed']],
        )]
        public string $status = 'draft',
    ) {
    }
}
