<?php

declare(strict_types=1);

namespace App\Mercure;

interface MercureSubscriptionCheckerInterface
{
    /**
     * Whether at least one client is currently subscribed to the trip's SSE topic
     * (`/trips/{tripId}`), read from the Mercure hub's subscription API.
     */
    public function hasActiveSubscriber(string $tripId): bool;
}
