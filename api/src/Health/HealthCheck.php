<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One dependency the readiness probe reports under `deps`.
 *
 * Reported in tag priority order, highest first: the order of the keys in the response is
 * part of what an operator reads, so it is set on each check rather than left to discovery.
 */
#[AutoconfigureTag(self::TAG)]
interface HealthCheck
{
    public const string TAG = 'app.health_check';

    /** The key the result is reported under. */
    public function name(): string;

    /** Whether this dependency being down turns readiness to 503. */
    public function isRequired(): bool;

    /**
     * Starts the check and returns what completes it.
     *
     * Work that can run in the background (an HTTP probe) is issued here, so that it is in
     * flight while the other checks complete; everything else runs in the returned closure.
     *
     * @return \Closure(): array<string, mixed> carries `status` (`ok`/`down`) and `latency_ms`
     */
    public function start(): \Closure;
}
