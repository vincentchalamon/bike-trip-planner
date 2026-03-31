<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripShare;

interface TripShareRepositoryInterface
{
    /**
     * Find a valid (non-expired) share by trip ID and token.
     */
    public function findValidShare(string $tripId, string $token): ?TripShare;
}
