<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Liveness and readiness probes for orchestration (Coolify, Uptime Kuma, smoke tests).
 *
 * - GET /api/healthz: lightweight liveness, no dependencies, always 200.
 * - GET /api/health:  readiness with parallel checks over critical dependencies.
 *                     200 when every required dep is green, 503 otherwise.
 */
final readonly class HealthController
{
    private const float CHECK_TIMEOUT = 1.0;

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'routing.client')]
        private HttpClientInterface $valhallaClient,
        #[Autowire(service: 'ollama.client')]
        private HttpClientInterface $ollamaClient,
        #[Autowire(service: 'mercure.health.client')]
        private HttpClientInterface $mercureClient,
        #[Autowire(service: 'limiter.health_liveness')]
        private RateLimiterFactory $healthLivenessLimiter,
        #[Autowire(service: 'limiter.health_readiness')]
        private RateLimiterFactory $healthReadinessLimiter,
        #[Autowire(param: 'app.commit_sha')]
        private string $commitSha,
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
    ) {
    }

    #[Route('/api/healthz', name: 'app_healthz', methods: ['GET'])]
    public function liveness(Request $request): JsonResponse
    {
        // Liveness must remain dependency-free: rate limiting is best-effort and
        // must never turn a healthy instance into a 5xx if Redis (or the cache
        // pool backing the limiter) happens to be unreachable.
        $this->enforceRateLimit($this->healthLivenessLimiter, $request, bestEffort: true);

        return new JsonResponse([
            'status' => 'ok',
            'commit' => $this->commitSha,
        ]);
    }

    #[Route('/api/health', name: 'app_health', methods: ['GET'])]
    public function readiness(Request $request): JsonResponse
    {
        // Readiness reports degradation via the response body; do not let the
        // rate limiter backend take down the probe itself.
        $this->enforceRateLimit($this->healthReadinessLimiter, $request, bestEffort: true);

        $deps = [];
        $deps['postgres'] = $this->checkPostgres();
        $deps['redis'] = $this->checkRedis();

        // Launch HTTP-based checks in parallel by issuing their requests up front.
        $pending = [
            'mercure' => $this->startHttpCheck('HEAD', '?topic=health', $this->mercureClient),
            'valhalla' => $this->startHttpCheck('GET', '/status', $this->valhallaClient),
            'ollama' => $this->startHttpCheck('GET', '/api/tags', $this->ollamaClient),
        ];

        foreach ($pending as $name => $pair) {
            $deps[$name] = $this->finishHttpCheck($pair);
        }

        $required = ['postgres', 'redis', 'mercure', 'valhalla'];
        $status = 'ok';
        foreach ($required as $dep) {
            if ('ok' !== ($deps[$dep]['status'] ?? 'down')) {
                $status = 'degraded';
                break;
            }
        }

        $httpStatus = 'ok' === $status ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $status,
            'commit' => $this->commitSha,
            'deps' => $deps,
        ], $httpStatus);
    }

    private function enforceRateLimit(RateLimiterFactory $factory, Request $request, bool $bestEffort = false): void
    {
        try {
            $limiter = $factory->create($request->getClientIp() ?? 'anonymous');

            if (!$limiter->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException();
            }
        } catch (TooManyRequestsHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The backing cache (e.g. Redis) is unavailable. Health probes must
            // not fail on infrastructure issues unrelated to the probe itself —
            // skip rate limiting rather than emit a 5xx.
            if (!$bestEffort) {
                throw $e;
            }
        }
    }

    /**
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function checkPostgres(): array
    {
        $start = hrtime(true);

        try {
            // Cap statement and any implicit reconnect to ~1s to avoid
            // letting the probe block on the OS TCP timeout (~30s) when
            // Postgres is unreachable.
            $this->connection->executeStatement('SET statement_timeout = 1000');
            $this->connection->executeQuery('SELECT 1');

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
        }
    }

    /**
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            $redis = new \Redis();
            $parsed = parse_url($this->redisUrl);
            if (false === $parsed || !isset($parsed['host'])) {
                throw new \RuntimeException('invalid redis url');
            }

            $host = $parsed['host'];
            $port = $parsed['port'] ?? 6379;
            $connected = $redis->connect($host, $port, self::CHECK_TIMEOUT);
            if (!$connected) {
                throw new \RuntimeException('connection failed');
            }

            $pong = $redis->ping();
            $redis->close();

            $ok = '+PONG' === $pong || true === $pong || 'PONG' === $pong;

            return [
                'status' => $ok ? 'ok' : 'down',
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

    /**
     * @return array{response: ?ResponseInterface, start: int, error: ?string}
     */
    private function startHttpCheck(string $method, string $url, HttpClientInterface $client): array
    {
        $start = hrtime(true);

        try {
            $response = $client->request($method, $url, [
                'timeout' => self::CHECK_TIMEOUT,
                'max_duration' => self::CHECK_TIMEOUT,
            ]);

            return ['response' => $response, 'start' => $start, 'error' => null];
        } catch (\Throwable $throwable) {
            return ['response' => null, 'start' => $start, 'error' => $this->sanitizeError($throwable)];
        }
    }

    /**
     * @param array{response: ?ResponseInterface, start: int, error: ?string} $pair
     *
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function finishHttpCheck(array $pair): array
    {
        $start = $pair['start'];

        if (null !== $pair['error'] || null === $pair['response']) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $pair['error'] ?? 'no response',
            ];
        }

        try {
            $code = $pair['response']->getStatusCode();
            // Drain the body so the response is fully consumed (releases the connection).
            $pair['response']->getContent(false);

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
