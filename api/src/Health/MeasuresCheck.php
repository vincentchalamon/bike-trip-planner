<?php

declare(strict_types=1);

namespace App\Health;

/**
 * What every check reports alongside its status: how long it took, and why it failed.
 */
trait MeasuresCheck
{
    private function elapsedMs(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }

    /**
     * Surface only a short, non-sensitive class name to avoid leaking
     * connection strings, hostnames, or stack traces in the public payload.
     */
    private function sanitizeError(\Throwable $e): string
    {
        $class = $e::class;
        $short = substr((string) strrchr($class, '\\'), 1);

        return '' !== $short ? $short : $class;
    }
}
