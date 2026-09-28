<?php

declare(strict_types=1);

namespace App\RateLimiter;

use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * The `Retry-After` a refused request is told: whole seconds until the limiter frees a token,
 * never less than one, so a client that honours it cannot come back in the same second.
 */
final class RetryAfter
{
    public static function seconds(RateLimit $limit, ClockInterface $clock): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - $clock->now()->getTimestamp());
    }
}
