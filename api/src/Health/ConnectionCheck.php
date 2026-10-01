<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;

/**
 * A plain `SELECT 1` over one Postgres connection.
 */
abstract readonly class ConnectionCheck implements HealthCheck
{
    use MeasuresCheck;

    /**
     * Each subclass takes its connection under its own parameter name, the one the container
     * binds: an inherited constructor would hand both checks the default connection.
     */
    abstract protected function connection(): Connection;

    public function start(): \Closure
    {
        return $this->check(...);
    }

    /**
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function check(): array
    {
        $start = hrtime(true);

        try {
            // Cap statement and any implicit reconnect to ~1s to avoid
            // letting the probe block on the OS TCP timeout (~30s) when
            // Postgres is unreachable.
            $this->connection()->executeStatement('SET statement_timeout = 1000');
            $this->connection()->executeQuery('SELECT 1');

            return [
                'status' => 'ok',
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $this->sanitizeError($throwable),
            ];
        } finally {
            // Under FrankenPHP worker mode the connection outlives the request, so a leaked
            // 1s ceiling would cap every later query.
            try {
                $this->connection()->executeStatement('RESET statement_timeout');
            } catch (\Throwable) {
            }
        }
    }
}
