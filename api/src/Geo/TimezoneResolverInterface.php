<?php

declare(strict_types=1);

namespace App\Geo;

interface TimezoneResolverInterface
{
    /**
     * Resolves the timezone that applies at the given position, so times computed
     * from coordinates (sunset, twilight…) can be displayed in the rider's local time.
     * Always returns a zone: the resolution degrades rather than fails.
     */
    public function resolve(float $lat, float $lon): \DateTimeZone;
}
