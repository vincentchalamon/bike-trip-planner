<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Market;

interface MarketRepositoryInterface
{
    /**
     * Find markets near a geographic point filtered by day of week.
     *
     * Uses a bounding-box pre-filter for speed, then haversine for precision.
     *
     * @return list<Market>
     */
    public function findNearEndpoint(
        float $lat,
        float $lon,
        int $radiusMeters,
        int $dayOfWeek,
    ): array;

    public function findByExternalId(string $externalId): ?Market;

    public function save(Market $market, bool $flush = false): void;
}
