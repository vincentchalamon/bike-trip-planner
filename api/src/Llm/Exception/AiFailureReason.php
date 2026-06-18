<?php

declare(strict_types=1);

namespace App\Llm\Exception;

/**
 * Classified reason a user's AI provider call failed (ADR-042), so callers can
 * degrade precisely and only retry what is worth retrying.
 *
 * - INVALID_TOKEN  401/403: the stored token is wrong/revoked → the user must
 *   reconfigure; never retried.
 * - QUOTA_EXCEEDED 429 with no Retry-After: the user's plan/credit is exhausted
 *   → fail fast, do not burn more calls.
 * - RATE_LIMITED   429 with Retry-After: a transient throttle → retry honouring
 *   the hint.
 * - UNAVAILABLE    5xx / timeout / transport: a transient provider outage →
 *   retry a bounded number of times.
 */
enum AiFailureReason: string
{
    case INVALID_TOKEN = 'invalid_token';
    case QUOTA_EXCEEDED = 'quota_exceeded';
    case RATE_LIMITED = 'rate_limited';
    case UNAVAILABLE = 'unavailable';

    /**
     * Whether retrying the same call could plausibly succeed.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RATE_LIMITED, self::UNAVAILABLE => true,
            self::INVALID_TOKEN, self::QUOTA_EXCEEDED => false,
        };
    }
}
