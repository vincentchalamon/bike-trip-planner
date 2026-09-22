<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\RedisHealthClientFactory;
use App\Health\WorkerHeartbeat;
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

    /**
     * Redis stream and consumer-group names behind the Messenger transports.
     *
     * Hardcoded rather than derived from the transports: reading a depth through
     * `messenger.receiver_locator` would go through the transport's own Connection,
     * which memoises its \Redis handle. Under FrankenPHP worker mode that handle
     * outlives a Redis restart, ext-redis does not reconnect, and readiness would
     * answer 503 forever — the very failure RedisHealthClientFactory exists to avoid.
     * The DSNs in compose.yaml supply only the stream (`/messages`, `/failed`), so the
     * group stays Symfony's default.
     */
    private const string ASYNC_STREAM = 'messages';

    private const string FAILED_STREAM = 'failed';

    private const string CONSUMER_GROUP = 'symfony';

    public function __construct(
        // Default PG-app connection (`public` schema): the `postgres` liveness check.
        private Connection $connection,
        // Shared read-only PG-référence connection (`osm` / `tourism`): the
        // reference-data reads and the `postgres_reference` connectivity check
        // (ADR-060). Bound by parameter name in services.php.
        private Connection $referenceConnection,
        #[Autowire(service: 'routing.client')]
        private HttpClientInterface $valhallaClient,
        #[Autowire(service: 'mercure.health.client')]
        private HttpClientInterface $mercureClient,
        #[Autowire(service: 'limiter.health_liveness')]
        private RateLimiterFactory $healthLivenessLimiter,
        #[Autowire(service: 'limiter.health_readiness')]
        private RateLimiterFactory $healthReadinessLimiter,
        private RedisHealthClientFactory $redisClientFactory,
        private WorkerHeartbeat $workerHeartbeat,
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
        $deps['postgres_reference'] = $this->checkReferencePostgres();
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

        // The metadata DB round-trips and the Redis reads run while the HTTP probes are in flight.
        $deps['reference_data'] = $this->checkReferenceData();
        $deps['messenger'] = $this->checkMessenger();

        foreach ($pending as $name => $pair) {
            $deps[$name] = $this->finishHttpCheck($pair);
        }

        // reference_data and postgres_reference are non-required: the shared
        // read-only PG-référence backs feature enrichment (POI/accommodations), not
        // the core trip flow which lives entirely in PG-app. An unreachable or
        // unprovisioned reference DB degrades features, it never takes readiness
        // down (ADR-040/060).
        //
        // mercure is non-required for the same reason, since ADR-065: it is the
        // invalidation channel, not a source of truth. Everything it carries is
        // retrievable by GET, a publish failure no longer fails the work that produced
        // it, and a client that misses an event resynchronises on its next read. An
        // unreachable hub costs latency, not correctness.
        //
        // messenger is required, and it is the point of this list: the whole product
        // answers 202 and delegates to a worker, so no live consumer means nothing the
        // API accepts will ever complete (ADR-075).
        $required = ['postgres', 'redis', 'valhalla', 'messenger'];
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
     * Connectivity of the shared read-only PG-référence (ADR-060): a plain
     * `SELECT 1` over the reference connection. Non-required — reported so an
     * operator sees the reference DB is unreachable, but it never flips readiness
     * (the reference index is a feature-enrichment source, ADR-040).
     *
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function checkReferencePostgres(): array
    {
        $start = hrtime(true);

        try {
            $this->referenceConnection->executeStatement('SET statement_timeout = 1000');
            $this->referenceConnection->executeQuery('SELECT 1');

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
     * Reports the async tier: whether anything is consuming, and how much is waiting.
     *
     * The whole product answers 202 and delegates to a worker, so a dead consumer used
     * to leave this probe green while every trip stayed `pending` until the tracker
     * expired half an hour later. That is the verdict here — and the only one: the two
     * depths are reported without a threshold. A depth threshold would be red whenever
     * the system is merely busy, the same mistake ADR-041 R5 was withdrawn for (#877),
     * and comparing the live count to WORKER_REPLICAS would be red through every
     * rolling restart. An absence is unambiguous; a number is not.
     *
     * @return array{status: string, latency_ms: int, workers_alive: int, queue_depth: int|null, failed_depth: int|null, error?: string}
     */
    private function checkMessenger(): array
    {
        $start = hrtime(true);

        try {
            $alive = $this->workerHeartbeat->aliveCount();
            $redis = $this->redisClientFactory->create();

            return [
                'status' => 0 === $alive ? 'down' : 'ok',
                'latency_ms' => $this->elapsedMs($start),
                'workers_alive' => $alive,
                'queue_depth' => $this->streamBacklog($redis, self::ASYNC_STREAM),
                // Nothing consumes `failed` (the worker only runs `messenger:consume async`),
                // so this is a dead-letter counter, not a backlog.
                'failed_depth' => $this->streamBacklog($redis, self::FAILED_STREAM),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'workers_alive' => 0,
                'queue_depth' => null,
                'failed_depth' => null,
                'error' => $this->sanitizeError($throwable),
            ];
        }
    }

    /**
     * Undelivered entries left for the consumer group, or null when the stream does not
     * exist yet (nothing has ever been enqueued) or the server predates the `lag` field.
     * In-flight, delivered-but-unacked entries are not counted.
     */
    private function streamBacklog(\Redis $redis, string $stream): ?int
    {
        try {
            /** @var list<array<string, mixed>>|false $groups */
            $groups = $redis->xInfo('GROUPS', $stream);
        } catch (\Throwable) {
            // XINFO raises on a stream that was never written to. An empty queue is not
            // a failure, and it must not drag the worker verdict down with it.
            return null;
        }

        if (!\is_array($groups)) {
            return null;
        }

        foreach ($groups as $group) {
            if (self::CONSUMER_GROUP !== ($group['name'] ?? null)) {
                continue;
            }

            $lag = $group['lag'] ?? null;

            return is_numeric($lag) ? (int) $lag : null;
        }

        return null;
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
            $this->referenceConnection->executeStatement('SET statement_timeout = 1000');

            $osm = $this->fetchProvisioningMetadata('osm');
            $tourism = $this->fetchProvisioningMetadata('tourism');

            $status = null === $osm && null === $tourism ? 'down' : 'ok';

            return [
                'status' => $status,
                'latency_ms' => $this->elapsedMs($start),
                'osm' => $osm,
                'tourism' => $tourism,
                'zones' => $this->fetchZones(),
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
                $this->referenceConnection->executeStatement('RESET statement_timeout');
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
            $row = $this->referenceConnection->fetchAssociative(
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
     * Reports the zone registry and the containment invariant of ADR-049 §6: **the
     * routing perimeter encompasses the reference perimeter**. Nothing maintains that —
     * the routing slugs are not derived from the registry — so the invariant is
     * *checked*, and this is where the check is visible after the fact. The provisioner
     * refuses to open a zone the graph does not cover; a violation here means the graph
     * shrank (or the reference index was seeded some other way) since.
     *
     * The comparison is between two explicit lists, `osm.zones.country` against
     * `osm.routing_perimeter.slug`, and cannot be geometric: a clipped Geofabrik regional
     * extract yields no country polygon to compare (#880).
     *
     * Like the rest of `reference_data`, this never flips readiness (ADR-040): an
     * unrouteable zone degrades that zone's trips, it does not take the instance down.
     *
     * @return array{open: list<array<string, mixed>>, routing_perimeter: list<string>, routing_containment: array{status: string, uncovered: list<string>}}
     */
    private function fetchZones(): array
    {
        $unknown = ['open' => [], 'routing_perimeter' => [], 'routing_containment' => ['status' => 'unknown', 'uncovered' => []]];

        try {
            $perimeter = $this->referenceConnection->fetchFirstColumn('SELECT slug FROM osm.routing_perimeter ORDER BY slug');
            $zones = $this->referenceConnection->fetchAllAssociative(
                <<<'SQL'
                    SELECT z.slug, z.name, z.country, z.opened_at, z.refreshed_at, z.pipeline_version,
                           (p.slug IS NOT NULL) AS routable
                    FROM osm.zones z
                    LEFT JOIN osm.routing_perimeter p ON p.slug = z.country
                    ORDER BY z.slug
                    SQL,
            );
        } catch (\Throwable) {
            // Registry absent (migrations not run on this instance): unknown, not a fault.
            return $unknown;
        }

        $open = array_map(
            static fn (array $row): array => [
                'slug' => \is_string($row['slug']) ? $row['slug'] : null,
                'name' => \is_string($row['name']) ? $row['name'] : null,
                'country' => \is_string($row['country']) ? $row['country'] : null,
                'opened_at' => \is_string($row['opened_at']) ? $row['opened_at'] : null,
                'refreshed_at' => \is_string($row['refreshed_at']) ? $row['refreshed_at'] : null,
                'pipeline_version' => is_numeric($row['pipeline_version']) ? (int) $row['pipeline_version'] : null,
                'routable' => \in_array($row['routable'], [true, 't', 1, '1'], true),
            ],
            $zones,
        );

        $uncovered = [];
        foreach ($open as $zone) {
            if (true !== $zone['routable'] && \is_string($zone['slug'])) {
                $uncovered[] = $zone['slug'];
            }
        }

        return [
            'open' => $open,
            'routing_perimeter' => array_values(array_map(
                static fn (mixed $slug): string => \is_string($slug) ? $slug : '',
                $perimeter,
            )),
            'routing_containment' => [
                'status' => [] === $uncovered ? 'ok' : 'violated',
                'uncovered' => $uncovered,
            ],
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
