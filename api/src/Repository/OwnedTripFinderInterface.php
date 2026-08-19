<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\TripRequest;

interface OwnedTripFinderInterface
{
    /**
     * Owned (non-anonymous) trips whose date range covers the given day, for the
     * weather-safety batch (#1124).
     *
     * @return list<TripRequest>
     */
    public function findOwnedTripsCoveringDate(\DateTimeImmutable $date): array;
}
