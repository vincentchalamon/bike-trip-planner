<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripShare;

interface TripShareRepositoryInterface
{
    /**
     * Find a valid (non-deleted) share by trip ID and token.
     */
    public function findValidShare(string $tripId, string $token): ?TripShare;

    /**
     * Find the active (non-deleted) share for a given trip.
     */
    public function findActiveByTrip(string $tripId): ?TripShare;
}
