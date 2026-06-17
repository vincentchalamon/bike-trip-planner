<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Shared Wikidata enrichment pass (ADR-040/041) used by both the OSM and the
 * DataTourisme importers: it enriches the Wikidata-bearing staging tables from
 * the persistent cache, querying Wikidata only for the Q-IDs that are missing or
 * older than the TTL.
 *
 * Runs after a schema's tables are loaded and before its atomic swap, against
 * the staging schema, so the enrichment ships with the dataset that goes live.
 * Resumable + memory-bounded:
 *
 * 1. collect the distinct Q-IDs straight from the staging tables (no app-side
 *    bookkeeping) into a scratch table;
 * 2. export those absent/stale in {@see provisioner.wikidata_cache};
 * 3. query only those from Wikidata, streaming one batch at a time into a COPY
 *    file, and upsert them into the cache (negative cache for no-data Q-IDs);
 * 4. UPDATE each table by joining the cache (fresh + previously cached), so
 *    cached Q-IDs enrich the fresh staging tables with no network call.
 *
 * Every target table must carry a `wikidata` column plus the enrichment columns
 * (description, opening_hours, website, image_url, wikipedia_url). Source-set
 * fields win when present (COALESCE); image_url / wikipedia_url are Wikidata-only.
 *
 * The cache and scratch tables live in a stable `provisioner` schema (NOT the
 * swapped schema); the pass creates them if absent so it works even when the API
 * migration has not run on this DB.
 */
final readonly class WikidataEnrichmentPass
{
    private const string CACHE_SCHEMA = 'provisioner';

    private const string CACHE_TABLE = 'provisioner.wikidata_cache';

    private const string CANDIDATES_TABLE = 'provisioner.wikidata_candidates';

    private const string FETCH_TABLE = 'provisioner.wikidata_fetch';

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql process factory; shared with the calling importer so commands are captured in tests
     */
    public function __construct(
        ?\Closure $processFactory = null,
        private WikidataEnricher $enricher = new WikidataEnricher(),
        private string $locale = 'fr',
        private int $cacheTtlDays = 30,
        private float $timeoutSeconds = 1800.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @param string       $stagingSchema schema whose tables are enriched (before its swap)
     * @param list<string> $tables        table names within $stagingSchema, each with a `wikidata` column + the enrichment columns
     *
     * @throws ImportFailedException
     */
    public function run(string $workDir, string $stagingSchema, array $tables): void
    {
        if ([] === $tables) {
            return;
        }

        // Ensure the persistent cache (the API migration may not have run here) and
        // fresh per-run scratch tables (drop any leftover from a prior crash; they
        // live in the stable schema, never in the swapped one).
        $this->psql(\sprintf(
            'CREATE SCHEMA IF NOT EXISTS %1$s; CREATE TABLE IF NOT EXISTS %2$s (qid text PRIMARY KEY, payload jsonb NOT NULL, fetched_at timestamptz NOT NULL); DROP TABLE IF EXISTS %3$s, %4$s; CREATE TABLE %3$s (qid text); CREATE TABLE %4$s (qid text, payload jsonb);',
            self::CACHE_SCHEMA,
            self::CACHE_TABLE,
            self::CANDIDATES_TABLE,
            self::FETCH_TABLE,
        ), 'psql prepare wikidata cache');

        // Collect the distinct Q-IDs straight from the staging tables.
        $union = implode(' UNION ', array_map(
            static fn (string $table): string => \sprintf('SELECT DISTINCT wikidata FROM %s.%s WHERE wikidata IS NOT NULL', $stagingSchema, $table),
            $tables,
        ));
        $this->psql(\sprintf('INSERT INTO %s (qid) %s;', self::CANDIDATES_TABLE, $union), 'psql collect wikidata candidates');

        // Export those missing or older than the TTL.
        $missingPath = $workDir.'/wikidata-missing.copy';
        $this->psql(\sprintf(
            "\\copy (SELECT cand.qid FROM %1\$s cand WHERE NOT EXISTS (SELECT 1 FROM %2\$s c WHERE c.qid = cand.qid AND c.fetched_at > now() - make_interval(days => %3\$d))) TO '%4\$s'",
            self::CANDIDATES_TABLE,
            self::CACHE_TABLE,
            $this->cacheTtlDays,
            $missingPath,
        ), 'psql select missing wikidata');

        // Query only the missing Q-IDs, streaming each batch into the COPY file,
        // then upsert them into the cache.
        $missing = $this->readColumn($missingPath);
        if ([] !== $missing) {
            $fetchPath = $workDir.'/wikidata-fetch.copy';
            $handle = fopen($fetchPath, 'w');
            if (false === $handle) {
                throw new ImportFailedException(\sprintf('Cannot open enrichment COPY file "%s"', $fetchPath));
            }

            foreach ($this->enricher->enrich($missing, $this->locale) as $row) {
                $payload = json_encode((object) ($row['enrichment'] ?? []), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
                fwrite($handle, $this->copyValue($row['qid'])."\t".$this->copyValue($payload)."\n");
            }

            fclose($handle);

            $this->psql(\sprintf("\\copy %s (qid, payload) FROM '%s'", self::FETCH_TABLE, $fetchPath), 'psql copy wikidata fetched');
            $this->psql(\sprintf(
                'INSERT INTO %1$s (qid, payload, fetched_at) SELECT qid, payload, now() FROM %2$s ON CONFLICT (qid) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at;',
                self::CACHE_TABLE,
                self::FETCH_TABLE,
            ), 'psql upsert wikidata cache');
        }

        // Enrich each table from the cache. Source-set fields win when present;
        // image_url / wikipedia_url are Wikidata-only.
        foreach ($tables as $table) {
            $this->psql(\sprintf(
                "UPDATE %1\$s.%2\$s t SET description = COALESCE(t.description, c.payload->>'description'), opening_hours = COALESCE(t.opening_hours, c.payload->>'openingHours'), website = COALESCE(t.website, c.payload->>'website'), image_url = c.payload->>'imageUrl', wikipedia_url = c.payload->>'wikipediaUrl' FROM %3\$s c WHERE t.wikidata = c.qid;",
                $stagingSchema,
                $table,
                self::CACHE_TABLE,
            ), \sprintf('psql enrich %s.%s', $stagingSchema, $table));
        }

        $this->psql(\sprintf('DROP TABLE IF EXISTS %s, %s;', self::CANDIDATES_TABLE, self::FETCH_TABLE), 'psql drop wikidata scratch tables');
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

    private function copyValue(string $value): string
    {
        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $value);
    }

    /**
     * @return list<string>
     */
    private function readColumn(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents || '' === trim($contents)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $contents)), static fn (string $line): bool => '' !== $line));
    }
}
