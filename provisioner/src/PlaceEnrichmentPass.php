<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Resolves the missing names of a staging table and applies the completeness gate before
 * promotion (ADR-049 §3/§4, issue #884).
 *
 * The mechanism is not new: {@see WikidataEnrichmentPass} already does exactly this for
 * Q-IDs — persistent cache outside any promoted schema, anti-join so only the undecided is
 * worked on, negative caching for what yields nothing, `COPY` streaming, then
 * `UPDATE ... JOIN`. This generalises it to names, and the shape is deliberately the same
 * so the two read as one idiom.
 *
 * Four properties worth stating, because each is an acceptance criterion:
 *
 * - **Re-opening an unchanged zone makes no enrichment call.** Every row already decided by
 *   this resolver version is excluded from the export, so the resolver sees nothing and no
 *   file is written.
 * - **A row already present and complete is neither read nor rewritten.** The candidate scan
 *   only looks at rows arriving without a name; the apply step is `COALESCE(a.name, …)`, so
 *   an existing value can never be replaced — the literal pattern of
 *   `WikidataEnrichmentPass`.
 * - **A rejection is remembered with the version that rejected it**, so bumping
 *   {@see NameResolver::VERSION} makes the next opening retry the `insufficient` entries and
 *   only those. Without it, a zone would stay at the data quality of the day it was opened.
 * - **The gate deletes from staging, not from live.** The promotion's `INSERT` therefore
 *   never violates the `CHECK`; the constraint exists so that a *bug* in this gate becomes a
 *   failed insert instead of a quietly thinned index.
 */
final readonly class PlaceEnrichmentPass
{
    private const string CACHE_SCHEMA = 'provisioner';

    private const string CACHE_TABLE = 'provisioner.place_enrichment';

    /**
     * Radius for the geometric match, in metres.
     *
     * Deliberately stricter than the 75 m of `App\Geo\NearbyNameDeduplicator`, whose match
     * is corroborated by equal names; this one has no name to corroborate it, so geometry is
     * the only evidence and it has to be stronger. 50 m is the starting point #885 proposes
     * and it is **not yet justified by a measurement** — the opening report now emits the
     * match and ambiguity counts precisely so the first real provisioning run produces the
     * numbers that will confirm or move it.
     */
    public const int DEFAULT_MATCH_RADIUS_METERS = 50;

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param string       $source            cache partition, also the report label ('osm', 'datatourisme')
     * @param string       $identity          SQL expression identifying a row `a` of the target table
     * @param list<string> $exemptCategories  categories the gate does not apply to, and which are therefore
     *                                        not resolved either (`shelter`, arbitrated by #878)
     * @param string|null  $matchTable        qualified table of curated places to match unnamed rows against
     *                                        (`<tourism staging>.accommodations`); null disables matching
     * @param int          $matchRadiusMeters strictly under the runtime deduplicator's 75 m: this match has no
     *                                        name to corroborate it, so it must be geometrically tighter
     */
    public function __construct(
        private string $source,
        private string $identity,
        private array $exemptCategories = [],
        private ?string $osmSchema = null,
        private ?string $liveSchema = null,
        ?\Closure $processFactory = null,
        private NameResolver $resolver = new NameResolver(),
        private float $timeoutSeconds = 1800.0,
        private ?string $matchTable = null,
        private int $matchRadiusMeters = self::DEFAULT_MATCH_RADIUS_METERS,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @param string|null $reportPath where to write the human-readable reject report; null skips it
     *
     * @return array{resolved: int, rejected: int, matched: int, ambiguous: int, reasons: array<string, int>}
     *
     * @throws ImportFailedException
     */
    public function run(string $workDir, string $stagingSchema, string $table, ?string $reportPath = null): array
    {
        // The cache may predate the API migration on this database (same reason
        // WikidataEnrichmentPass creates its own), and the scratch table is scoped to the
        // staging schema so two passes never drop each other's rows.
        $scratch = \sprintf('%s.place_resolved_%s', self::CACHE_SCHEMA, (string) preg_replace('/[^a-z0-9_]/i', '_', $stagingSchema));
        $this->psql(\sprintf(
            'CREATE SCHEMA IF NOT EXISTS %1$s; CREATE TABLE IF NOT EXISTS %2$s (source text NOT NULL, source_id text NOT NULL, payload jsonb NOT NULL, status text NOT NULL, resolver_version integer NOT NULL, fetched_at timestamptz NOT NULL, PRIMARY KEY (source, source_id)); DROP TABLE IF EXISTS %3$s; CREATE TABLE %3$s (source_id text, payload jsonb, status text);',
            self::CACHE_SCHEMA,
            self::CACHE_TABLE,
            $scratch,
        ), 'psql prepare place enrichment cache');

        $candidatesPath = $workDir.'/place-candidates.tsv';
        $this->psql($this->exportCandidates($stagingSchema, $table, $candidatesPath), 'psql export name candidates');

        $decisions = $this->resolveAll($candidatesPath);
        $counts = ['resolved' => 0, 'rejected' => 0, 'matched' => 0, 'ambiguous' => 0, 'reasons' => []];

        if ([] !== $decisions) {
            $copyPath = $workDir.'/place-resolved.copy';
            $handle = fopen($copyPath, 'w');
            if (false === $handle) {
                throw new ImportFailedException(\sprintf('Cannot open enrichment COPY file "%s"', $copyPath));
            }

            try {
                foreach ($decisions as $decision) {
                    fwrite($handle, implode("\t", [
                        $this->copyValue($decision['source_id']),
                        $this->copyValue($decision['payload']),
                        $this->copyValue($decision['status']),
                    ])."\n");
                }
            } finally {
                fclose($handle);
            }

            $this->psql(\sprintf("\\copy %s (source_id, payload, status) FROM '%s'", $scratch, $copyPath), 'psql copy resolved names');
            // Negative decisions are cached too, with the version that made them: that is
            // what makes a re-opening cheap and a resolver improvement retroactive.
            $this->psql(\sprintf(
                'INSERT INTO %1$s (source, source_id, payload, status, resolver_version, fetched_at) SELECT %2$s, source_id, payload, status, %3$d, now() FROM %4$s ON CONFLICT (source, source_id) DO UPDATE SET payload = excluded.payload, status = excluded.status, resolver_version = excluded.resolver_version, fetched_at = excluded.fetched_at;',
                self::CACHE_TABLE,
                $this->literal($this->source),
                NameResolver::VERSION,
                $scratch,
            ), 'psql upsert place enrichment cache');
        }

        // COALESCE only: completion, never rewriting (ADR-049 §4). A matched DataTourisme
        // record brings its description, site and hours along with the name (#885), under the
        // same rule — so an OSM value that exists always wins.
        $this->psql(\sprintf(
            "UPDATE %1\$s.%2\$s a SET name = COALESCE(a.name, c.payload->>'name'), description = COALESCE(a.description, c.payload->>'description'), website = COALESCE(a.website, c.payload->>'website'), opening_hours = COALESCE(a.opening_hours, c.payload->>'opening_hours') FROM %3\$s c WHERE c.source = %4\$s AND c.source_id = %5\$s AND c.status = 'resolved';",
            $stagingSchema,
            $table,
            self::CACHE_TABLE,
            $this->literal($this->source),
            $this->identity,
        ), \sprintf('psql apply resolved names to %s.%s', $stagingSchema, $table));

        $rejectedPath = $workDir.'/place-rejected.tsv';
        $this->psql(\sprintf(
            "\\copy (SELECT count(*) FROM %s.%s a WHERE %s) TO '%s'",
            $stagingSchema,
            $table,
            $this->gatePredicate($table),
            $rejectedPath,
        ), 'psql count gated rows');
        $counts['rejected'] = (int) ($this->readLines($rejectedPath)[0] ?? '0');

        // Written before the delete, or there would be nothing left to report on.
        if (null !== $reportPath) {
            $this->writeRejectReport($stagingSchema, $table, $reportPath);
        }

        // The gate itself. Deleting from staging keeps the promotion's INSERT clean, so the
        // CHECK on the live table only ever fires on a bug here — which is the point.
        $this->psql(\sprintf(
            'DELETE FROM %s.%s a WHERE %s;',
            $stagingSchema,
            $table,
            $this->gatePredicate($table),
        ), 'psql apply completeness gate');

        $this->psql(\sprintf('DROP TABLE IF EXISTS %s;', $scratch), 'psql drop place enrichment scratch');

        foreach ($decisions as $decision) {
            if ('resolved' === $decision['status']) {
                ++$counts['resolved'];
                if ('datatourisme' === $decision['via']) {
                    ++$counts['matched'];
                }

                continue;
            }

            $reason = $decision['reason'];
            if ('ambiguous_match' === $reason) {
                ++$counts['ambiguous'];
            }

            $counts['reasons'][$reason] = ($counts['reasons'][$reason] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Rows arriving without a usable name that no current-version decision covers yet.
     *
     * A `resolved` entry is excluded whatever its version: it already produced a name, and
     * re-resolving could *change* it, which the no-rewrite rule forbids. An `insufficient`
     * entry from an older version is included — that is the retroactivity.
     */
    private function exportCandidates(string $stagingSchema, string $table, string $path): string
    {
        $boundaries = \sprintf('%s.admin_boundaries', $this->osmSchema ?? $stagingSchema);

        return \sprintf(
            <<<'SQL'
                \copy (SELECT %1$s,
                              a.category,
                              coalesce(a.tags::text, '{}'),
                              coalesce(w.payload->>'label', ''),
                              coalesce((SELECT b.name FROM %2$s b WHERE b.admin_level = 8 AND ST_Covers(b.geom, a.geom) LIMIT 1), ''),
                              %10$s
                         FROM %3$s.%4$s a
                         LEFT JOIN provisioner.wikidata_cache w ON w.qid = a.wikidata
                        WHERE nullif(btrim(a.name), '') IS NULL
                          AND %5$s
                          AND NOT EXISTS (SELECT 1 FROM %6$s c WHERE c.source = %7$s AND c.source_id = %1$s AND (c.status = 'resolved' OR c.resolver_version >= %8$d))) TO '%9$s'
                SQL,
            $this->identity,
            $boundaries,
            $stagingSchema,
            $table,
            $this->notExempt(),
            self::CACHE_TABLE,
            $this->literal($this->source),
            NameResolver::VERSION,
            $path,
            $this->matchExpression(),
        );
    }

    /**
     * Writes the rejects an operator can act on, **nearest to a signed cycle route first**.
     *
     * That ordering is what makes the exercise finite. The signed cycle routes are already
     * imported (`tier1.lua`, relations `type=route, route=bicycle`), so ranking by distance to
     * them is free — and it means an operator works the thirty rejects that border a
     * véloroute instead of the three thousand lost in open country. The human effort is
     * bounded by construction rather than by discipline.
     *
     * Columns: source, source id, category, latitude, longitude, resolved commune, the motive,
     * the metres to the nearest cycle route, and the raw tags — everything a human needs to
     * decide, and nothing that needs a second query to interpret.
     *
     * @throws ImportFailedException
     */
    private function writeRejectReport(string $stagingSchema, string $table, string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new ImportFailedException(\sprintf('Cannot create the report directory "%s"', $directory));
        }

        $osmSchema = $this->osmSchema ?? $stagingSchema;
        // KNN (`<->`) rather than min(ST_Distance(...)): the operator uses the GiST index on
        // cycle_routes.geom, so this is one index probe per reject instead of a full scan of
        // every route. Ordering happens in degrees and the reported distance in metres — good
        // enough to rank, exact enough to read.
        $distance = \sprintf(
            '(SELECT round(ST_Distance(r.geom::geography, a.geom::geography)::numeric, 1) FROM %s.cycle_routes r ORDER BY r.geom <-> a.geom LIMIT 1)',
            $osmSchema,
        );

        $this->psql(\sprintf(
            <<<'SQL'
                \copy (SELECT %10$s AS source,
                              %1$s AS source_id,
                              a.category,
                              round(ST_Y(a.geom)::numeric, 6) AS lat,
                              round(ST_X(a.geom)::numeric, 6) AS lon,
                              coalesce((SELECT b.name FROM %2$s.admin_boundaries b WHERE b.admin_level = 8 AND ST_Covers(b.geom, a.geom) LIMIT 1), '') AS commune,
                              coalesce(c.payload->>'reason', 'unknown') AS reason,
                              %3$s AS cycle_route_m,
                              coalesce(a.tags::text, '{}') AS tags
                         FROM %4$s.%5$s a
                         LEFT JOIN %6$s c ON c.source = %7$s AND c.source_id = %1$s
                        WHERE %8$s
                        ORDER BY %3$s NULLS LAST) TO '%9$s' WITH (FORMAT csv, DELIMITER E'\t', HEADER true)
                SQL,
            $this->identity,
            $osmSchema,
            $distance,
            $stagingSchema,
            $table,
            self::CACHE_TABLE,
            $this->literal($this->source),
            $this->gatePredicate($table),
            $path,
            $this->literal($this->source),
        ), \sprintf('psql write reject report for %s.%s', $stagingSchema, $table));
    }

    /**
     * The curated candidates within the radius, as one jsonb: how many there are, and the
     * single one's fields (`min()` over one row is that row).
     *
     * The count is what makes the conservative behaviour possible. Attributing the wrong name
     * to an accommodation is worse than attributing none — the rider books elsewhere, or turns
     * up at the wrong place — so two candidates produce a rejection rather than a pick, and the
     * resolver is never handed a reason to choose between them.
     *
     * Category equality is the compatibility rule: both sides use the same vocabulary, and a
     * looser rule is exactly how a hotel would inherit its restaurant's name.
     */
    private function matchExpression(): string
    {
        if (null === $this->matchTable) {
            return "'{}'";
        }

        return \sprintf(
            <<<'SQL'
                coalesce((SELECT jsonb_build_object(
                                   'n', count(*),
                                   'id', min(t.id),
                                   'name', min(t.name),
                                   'description', min(t.description),
                                   'website', min(t.website),
                                   'opening_hours', min(t.opening_hours),
                                   'distance_m', round(min(ST_Distance(t.geom::geography, a.geom::geography))::numeric, 1))
                            FROM %1$s t
                           WHERE t.category = a.category
                             AND ST_DWithin(t.geom::geography, a.geom::geography, %2$d))::text, '{}')
                SQL,
            $this->matchTable,
            $this->matchRadiusMeters,
        );
    }

    /**
     * Rows the gate refuses: still without a name after the cascade, not exempt, and not
     * already present in the live table.
     *
     * That last clause is what stops an operator's manual correction from coming back
     * (#886). Once an `override.tsv` has inserted the row into live, re-opening the zone
     * re-imports the same anonymous row into staging — and without this it would be counted
     * and listed in `rejected.tsv` again, every single time, asking to be fixed once more.
     * The promotion's own anti-join would skip it regardless, so excluding it here changes
     * only what is reported, which is exactly the point.
     */
    private function gatePredicate(string $table): string
    {
        $predicate = \sprintf("nullif(btrim(a.name), '') IS NULL AND %s", $this->notExempt());

        if (null === $this->liveSchema) {
            return $predicate;
        }

        return \sprintf(
            '%s AND NOT EXISTS (SELECT 1 FROM %s.%s l WHERE %s)',
            $predicate,
            $this->liveSchema,
            $table,
            $this->liveIdentityMatch(),
        );
    }

    /**
     * The identity predicate against the live table, derived from the same expression the
     * cache is keyed on so the two cannot disagree: `a.osm_type || '/' || a.osm_id` becomes
     * the pair of column comparisons, `a.id` the single one.
     */
    private function liveIdentityMatch(): string
    {
        if (str_contains($this->identity, 'osm_type')) {
            return 'l.osm_type = a.osm_type AND l.osm_id = a.osm_id';
        }

        return 'l.id = a.id';
    }

    private function notExempt(): string
    {
        if ([] === $this->exemptCategories) {
            return 'true';
        }

        return \sprintf(
            'a.category NOT IN (%s)',
            implode(', ', array_map($this->literal(...), $this->exemptCategories)),
        );
    }

    /**
     * @return list<array{source_id: string, payload: string, status: string, via: string, reason: string}>
     */
    private function resolveAll(string $path): array
    {
        $decisions = [];
        foreach ($this->readLines($path) as $line) {
            $fields = explode("\t", $line);
            if (6 !== \count($fields)) {
                continue;
            }

            [$sourceId, $category, $tagsJson, $wikidataLabel, $locality, $matchJson] = $fields;
            $decision = $this->resolver->resolve(
                $category,
                $this->decodeTags($tagsJson),
                '' === $wikidataLabel ? null : $wikidataLabel,
                '' === $locality ? null : $locality,
                $this->decodeMatch($matchJson),
            );

            $resolved = null !== $decision['name'];
            // The payload is the audit trail #885 asks for: which step produced the name, and
            // for a geometric match the record it came from and how far away it was. A
            // rejection records how many candidates made it ambiguous.
            $payload = $resolved
                ? array_filter([
                    'name' => $decision['name'],
                    'via' => $decision['via'],
                    'description' => $decision['description'] ?? null,
                    'website' => $decision['website'] ?? null,
                    'opening_hours' => $decision['opening_hours'] ?? null,
                    'matched_id' => $decision['matched_id'] ?? null,
                    'distance_m' => $decision['distance_m'] ?? null,
                ], static fn (mixed $value): bool => null !== $value)
                : array_filter([
                    'reason' => $decision['reason'] ?? 'unknown',
                    'candidates' => $decision['candidates'] ?? null,
                ], static fn (mixed $value): bool => null !== $value);

            $decisions[] = [
                'source_id' => $sourceId,
                'payload' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}',
                'status' => $resolved ? 'resolved' : 'insufficient',
                'via' => $resolved ? (string) $decision['via'] : '',
                'reason' => $resolved ? '' : ($decision['reason'] ?? 'unknown'),
            ];
        }

        return $decisions;
    }

    /**
     * @return array{n: int, id: ?string, name: ?string, description: ?string, website: ?string, opening_hours: ?string, distance_m: ?float}|null null when matching is off or nothing was in range
     */
    private function decodeMatch(string $json): ?array
    {
        $decoded = json_decode(str_replace(['\\t', '\\n', '\\r', '\\\\'], ["\t", "\n", "\r", '\\'], $json), true);
        if (!\is_array($decoded) || !isset($decoded['n']) || !is_numeric($decoded['n']) || 0 === (int) $decoded['n']) {
            return null;
        }

        return [
            'n' => (int) $decoded['n'],
            'id' => \is_string($decoded['id'] ?? null) ? $decoded['id'] : null,
            'name' => \is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
            'description' => \is_string($decoded['description'] ?? null) ? $decoded['description'] : null,
            'website' => \is_string($decoded['website'] ?? null) ? $decoded['website'] : null,
            'opening_hours' => \is_string($decoded['opening_hours'] ?? null) ? $decoded['opening_hours'] : null,
            'distance_m' => is_numeric($decoded['distance_m'] ?? null) ? (float) $decoded['distance_m'] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function decodeTags(string $json): array
    {
        // COPY escapes tabs and newlines inside the jsonb text; undo that before decoding.
        $decoded = json_decode(str_replace(['\\t', '\\n', '\\r', '\\\\'], ["\t", "\n", "\r", '\\'], $json), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $tags[$key] = (string) $value;
            }
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents || '' === trim($contents)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line, "\r\n"), explode("\n", $contents)),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }

    private function copyValue(string $value): string
    {
        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $value);
    }

    private function literal(string $value): string
    {
        return ZonePromotion::literal($value);
    }

    /**
     * @throws ImportFailedException
     */
    private function psql(string $sql, string $label): void
    {
        $process = ($this->processFactory)(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $sql]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $processTimedOutException) {
            throw new ImportFailedException(\sprintf('%s timed out after %.1fs', $label, $this->timeoutSeconds), 0, $processTimedOutException);
        }

        if (!$process->isSuccessful()) {
            throw new ImportFailedException(\sprintf("%s failed (exit %s).\nStderr: %s", $label, (string) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
