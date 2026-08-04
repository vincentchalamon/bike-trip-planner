<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\RedisHealthClientFactory;
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
        #[Autowire(service: 'mercure.health.client')]
        private HttpClientInterface $mercureClient,
        #[Autowire(service: 'limiter.health_liveness')]
        private RateLimiterFactory $healthLivenessLimiter,
        #[Autowire(service: 'limiter.health_readiness')]
        private RateLimiterFactory $healthReadinessLimiter,
        private RedisHealthClientFactory $redisClientFactory,
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
        // The AI tier is no longer a server dependency (ADR-042): it is an optional,
        // per-user cloud provider reached with the user's own token, so it is not
        // probed here. AI availability is surfaced to the PWA via the account
        // AI-settings: AI is available once the user has configured a provider + token.
        $pending = [
            'mercure' => $this->startHttpCheck('HEAD', '?topic=health', $this->mercureClient),
            'valhalla' => $this->startHttpCheck('GET', '/status', $this->valhallaClient),
        ];

        // The two metadata DB round-trips run while the HTTP probes are in flight.
        $deps['reference_data'] = $this->checkReferenceData();

        foreach ($pending as $name => $pair) {
            $deps[$name] = $this->finishHttpCheck($pair);
        }

        // reference_data is non-required: an unprovisioned PostGIS index degrades
        // features (fewer/no POI), it never takes readiness down (ADR-040).
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
            // A fresh connection per check (see RedisHealthClientFactory): never
            // reuse a connection a Redis restart may have broken in the worker.
            $pong = $this->redisClientFactory->create()->ping();
            $ok = true === $pong || '+PONG' === $pong || 'PONG' === $pong;

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
     * Sources whose provisioning metadata is reported; doubles as the allowlist
     * for the schema name interpolated into the metadata query.
     *
     * @var list<string>
     */
    private const array SOURCES = ['osm', 'tourism'];

    /**
     * Reports the state of the local-first PostGIS reference index (ADR-040/041):
     * the last refresh timestamp, its raw age, the per-table feature counts and
     * the per-table completeness ratios the provisioner records in osm.metadata /
     * tourism.metadata.
     *
     * The age carries **no** staleness verdict, and must not grow one back: no
     * scheduler refreshes these sources since ADR-036 removed the OSM cron, so any
     * threshold would be permanently red — and since data obsolescence is assumed
     * (opening a zone is a deliberate act, the data is dated by construction and
     * the user is the one who verifies), a threshold has nothing to judge. Age
     * stays an internal metric.
     *
     * Non-critical: `down` (never provisioned) degrades features only — it never
     * flips readiness, so the probe stays 200 (ADR-040).
     *
     * @return array<string, mixed>
     */
    private function checkReferenceData(): array
    {
        $start = hrtime(true);

        try {
            $this->connection->executeStatement('SET statement_timeout = 1000');

            $osm = $this->fetchProvisioningMetadata('osm');
            $tourism = $this->fetchProvisioningMetadata('tourism');

            $status = null === $osm && null === $tourism ? 'down' : 'ok';

            return [
                'status' => $status,
                'latency_ms' => $this->elapsedMs($start),
                'osm' => $osm,
                'tourism' => $tourism,
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $this->sanitizeError($throwable),
            ];
        } finally {
            // Reset the per-check ceiling so a persistent connection (FrankenPHP
            // worker mode) does not inherit the 1s limit on later queries.
            try {
                $this->connection->executeStatement('RESET statement_timeout');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array{refreshed_at: ?string, age_seconds: ?int, feature_counts: array<string, mixed>, completeness: array<string, mixed>, rejections: array<string, mixed>}|null null when the schema was never provisioned (no metadata row)
     */
    private function fetchProvisioningMetadata(string $schema): ?array
    {
        // The schema name is interpolated into SQL; allowlist it so a future
        // caller can never turn this into an injection point.
        if (!\in_array($schema, self::SOURCES, true)) {
            return null;
        }

        try {
            // `SELECT *` on purpose: an index provisioned before the completeness
            // columns existed must still report its counts rather than read as
            // unprovisioned, which naming the columns here would cause.
            // Age is computed in SQL (now() - refreshed_at) to stay timezone-safe.
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT *, EXTRACT(EPOCH FROM (now() - refreshed_at))::bigint AS age_seconds FROM %s.metadata LIMIT 1', $schema),
            );
        } catch (\Throwable) {
            // Schema/table absent (never migrated on this instance): treat as unprovisioned.
            return null;
        }

        if (false === $row) {
            return null;
        }

        return [
            'refreshed_at' => \is_string($row['refreshed_at']) ? $row['refreshed_at'] : null,
            'age_seconds' => is_numeric($row['age_seconds']) ? (int) $row['age_seconds'] : null,
            'feature_counts' => $this->decodeJsonColumn($row['feature_counts'] ?? null),
            'completeness' => $this->decodeJsonColumn($row['completeness'] ?? null),
            'rejections' => $this->decodeJsonColumn($row['rejections'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        /* @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
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
