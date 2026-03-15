<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RecalculateStages
{
    /**
     * @param list<int> $affectedIndices
     */
    public function __construct(
        public string $tripId,
        public array $affectedIndices,
        public bool $skipAccommodationScan = false,
        public bool $skipGeographicScans = false,
    ) {
    }
}
