<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A dependency reached over HTTP: anything below 500 is up.
 *
 * The request is issued in {@see self::start()} and only waited for in the closure it returns,
 * so the probes are in flight together and alongside the database and Redis checks.
 */
abstract readonly class HttpCheck implements HealthCheck
{
    use MeasuresCheck;

    private const float CHECK_TIMEOUT = 1.0;

    public function __construct(
        private HttpClientInterface $client,
        private string $method,
        private string $url,
    ) {
    }

    public function start(): \Closure
    {
        $start = hrtime(true);

        try {
            $response = $this->client->request($this->method, $this->url, [
                'timeout' => self::CHECK_TIMEOUT,
                'max_duration' => self::CHECK_TIMEOUT,
            ]);
        } catch (\Throwable $throwable) {
            $error = $this->sanitizeError($throwable);

            return fn (): array => [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $error,
            ];
        }

        return fn (): array => $this->finish($response, $start);
    }

    /**
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function finish(ResponseInterface $response, int $start): array
    {
        try {
            $code = $response->getStatusCode();
            // Drain the body so the response is fully consumed (releases the connection).
            $response->getContent(false);

            return [
                'status' => $code < 500 ? 'ok' : 'down',
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $this->sanitizeError($throwable),
            ];
        }
    }
}
