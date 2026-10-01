<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The provisioning state of the shared read-only PG-reference (ADR-040/041/049).
 *
 * Non-required: the reference index backs feature enrichment (POI/accommodations), not the
 * core trip flow which lives entirely in PG-app. An unreachable or unprovisioned reference DB
 * degrades features, it never takes readiness down (ADR-040/060).
 */
#[AsTaggedItem(priority: 40)]
final readonly class ReferenceDataCheck implements HealthCheck
{
    use MeasuresCheck;

    /**
     * Sources whose provisioning metadata is reported; doubles as the allowlist
     * for the schema name interpolated into the metadata query.
     *
     * @var list<string>
     */
    private const array SOURCES = ['osm', 'tourism'];

    // Bound by parameter name in services.php.
    public function __construct(
        private Connection $referenceConnection,
    ) {
    }

    public function name(): string
    {
        return 'reference_data';
    }

    public function isRequired(): bool
    {
        return false;
    }

    public function start(): \Closure
    {
        return $this->check(...);
    }

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
    private function check(): array
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
}
