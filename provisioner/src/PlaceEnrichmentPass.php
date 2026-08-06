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
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param string       $source           cache partition, also the report label ('osm', 'datatourisme')
     * @param string       $identity         SQL expression identifying a row `a` of the target table
     * @param list<string> $exemptCategories categories the gate does not apply to, and which are therefore
     *                                       not resolved either (`shelter`, arbitrated by #878)
     * @param string|null  $boundariesSchema schema holding `admin_boundaries` for the offline locality
     *                                       lookup; null uses the staging schema, which is where the zone
     *                                       being opened has its own freshly imported boundaries
     */
    public function __construct(
        private string $source,
        private string $identity,
        private array $exemptCategories = [],
        private ?string $boundariesSchema = null,
        ?\Closure $processFactory = null,
        private NameResolver $resolver = new NameResolver(),
        private float $timeoutSeconds = 1800.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @return array{resolved: int, rejected: int, reasons: array<string, int>}
     *
     * @throws ImportFailedException
     */
    public function run(string $workDir, string $stagingSchema, string $table): array
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
        $counts = ['resolved' => 0, 'rejected' => 0, 'reasons' => []];

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

        // COALESCE only: completion, never rewriting (ADR-049 §4).
        $this->psql(\sprintf(
            "UPDATE %1\$s.%2\$s a SET name = COALESCE(a.name, c.payload->>'name') FROM %3\$s c WHERE c.source = %4\$s AND c.source_id = %5\$s AND c.status = 'resolved';",
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
            $this->gatePredicate(),
            $rejectedPath,
        ), 'psql count gated rows');
        $counts['rejected'] = (int) ($this->readLines($rejectedPath)[0] ?? '0');

        // The gate itself. Deleting from staging keeps the promotion's INSERT clean, so the
        // CHECK on the live table only ever fires on a bug here — which is the point.
        $this->psql(\sprintf(
            'DELETE FROM %s.%s a WHERE %s;',
            $stagingSchema,
            $table,
            $this->gatePredicate(),
        ), 'psql apply completeness gate');

        $this->psql(\sprintf('DROP TABLE IF EXISTS %s;', $scratch), 'psql drop place enrichment scratch');

        foreach ($decisions as $decision) {
            if ('resolved' === $decision['status']) {
                ++$counts['resolved'];
                continue;
            }

            $reason = $decision['reason'];
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
        $boundaries = \sprintf('%s.admin_boundaries', $this->boundariesSchema ?? $stagingSchema);

        return \sprintf(
            <<<'SQL'
                \copy (SELECT %1$s,
                              a.category,
                              coalesce(a.tags::text, '{}'),
                              coalesce(w.payload->>'label', ''),
                              coalesce((SELECT b.name FROM %2$s b WHERE b.admin_level = 8 AND ST_Covers(b.geom, a.geom) LIMIT 1), '')
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
        );
    }

    /**
     * Rows the gate refuses: still without a name after the cascade, and not exempt.
     */
    private function gatePredicate(): string
    {
        return \sprintf("nullif(btrim(a.name), '') IS NULL AND %s", $this->notExempt());
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
     * @return list<array{source_id: string, payload: string, status: string, reason: string}>
     */
    private function resolveAll(string $path): array
    {
        $decisions = [];
        foreach ($this->readLines($path) as $line) {
            $fields = explode("\t", $line);
            if (5 !== \count($fields)) {
                continue;
            }

            [$sourceId, $category, $tagsJson, $wikidataLabel, $locality] = $fields;
            $decision = $this->resolver->resolve(
                $category,
                $this->decodeTags($tagsJson),
                '' === $wikidataLabel ? null : $wikidataLabel,
                '' === $locality ? null : $locality,
            );

            $resolved = null !== $decision['name'];
            $payload = $resolved
                ? ['name' => $decision['name'], 'via' => $decision['via']]
                : ['reason' => $decision['reason'] ?? 'unknown'];

            $decisions[] = [
                'source_id' => $sourceId,
                'payload' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}',
                'status' => $resolved ? 'resolved' : 'insufficient',
                'reason' => $resolved ? '' : ($decision['reason'] ?? 'unknown'),
            ];
        }

        return $decisions;
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
