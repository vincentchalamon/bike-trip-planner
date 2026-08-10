<?php

declare(strict_types=1);

namespace App\Tourism;

interface EventRepositoryInterface
{
    /**
     * DataTourisme events active on $date (start_date <= $date <= end_date)
     * within $radiusMeters of the point, ranked by distance to it, capped, and
     * limited to events carrying a link (`url IS NOT NULL AND url <> ''`).
     *
     * @param string $date Y-m-d
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string}>
     */
    public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array;
}
