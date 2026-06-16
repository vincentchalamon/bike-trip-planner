<?php

declare(strict_types=1);

namespace App\Tourism;

interface EventRepositoryInterface
{
    /**
     * DataTourisme events active on $date (start_date <= $date <= end_date)
     * within $radiusMeters of the point, nearest dates first.
     *
     * @param string $date Y-m-d
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: ?string, description: ?string, priceMin: ?float}>
     */
    public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array;
}
