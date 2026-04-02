<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripShare;

interface TripShareRepositoryInterface
{
    public function findActiveByTrip(string $tripId): ?TripShare;

    public function findByShortCode(string $shortCode): ?TripShare;
}
