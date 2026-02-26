<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;

/**
 * Detects whether a PATCH request actually changes the trip parameters.
 * Avoids redundant computation re-dispatches on unchanged payloads.
 */
interface IdempotencyCheckerInterface
{
    /**
     * Returns true if the request parameters have changed since the last saved hash.
     */
    public function hasChanged(string $tripId, TripRequest $newRequest): bool;

    /**
     * Persists a hash of the current request parameters for future comparison.
     */
    public function saveHash(string $tripId, TripRequest $request): void;
}
