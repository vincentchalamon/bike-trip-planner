<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports the DataTourisme flux into the local-first `tourism` PostGIS schema
 * (ADR-040), replacing the runtime DataTourisme REST API.
 *
 * Flow, behind an atomic schema swap (same shape as {@see PostgisImporter}):
 * download the flux ZIP, stream its `objects/` JSON-LD files one by one,
 * {@see DataTourismeMapper map} each to a cultural-POI / accommodation / event
 * row, write the rows to per-table COPY files (constant memory regardless of the
 * ~390k objects), bulk-load them into a fresh `tourism_staging` schema via psql
 * COPY, then rename the staging schema onto the live `tourism` in one
 * transaction. A failed import leaves the previous dataset intact.
 *
 * Rows are emitted in PostgreSQL text COPY format (`\N` = NULL, tab-separated,
 * backslash-escaped); the geometry column receives EWKT (`SRID=4326;POINT(lon
 * lat)`) which PostGIS parses on input. The DB connection comes from the libpq
 * environment (PG*), inherited by psql.
 *
 * @phpstan-import-type Row from DataTourismeMapper
 */
final readonly class DataTourismeImporter
{
    private const string STAGING_SCHEMA = 'tourism_staging';

    private const string LIVE_SCHEMA = 'tourism';

    /**
     * Target tables and their COPY column order. `geom` always comes last and is
     * fed EWKT. Must match Version20260616120000 / Version20260616140000 (the
     * live-schema bootstraps).
     *
     * @var array<string, list<string>>
     */
    private const array TABLE_COLUMNS = [
        'cultural_pois' => ['id', 'name', 'category', 'opening_hours', 'description', 'wikidata', 'tags', 'geom'],
        'food_pois' => ['id', 'name', 'category', 'opening_hours', 'description', 'wikidata', 'tags', 'geom'],
        'accommodations' => ['id', 'name', 'category', 'capacity', 'price', 'description', 'tags', 'geom'],
        'events' => ['id', 'name', 'category', 'start_date', 'end_date', 'url', 'description', 'price_min', 'tags', 'geom'],
    ];

    private const array STAGING_DDL = [
        'cultural_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, website text, image_url text, wikipedia_url text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'food_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, website text, image_url text, wikipedia_url text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'accommodations' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, capacity int, price numeric(10, 2), description text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'events' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, start_date date, end_date date, url text, description text, price_min numeric(10, 2), tags jsonb, geom geometry(Point, 4326) NOT NULL',
    ];

    /**
     * Tables enriched from Wikidata (those carrying a `wikidata` Q-ID column),
     * with the columns the {@see WikidataEnricher} fills. Source-provided fields
     * (description, opening_hours) are kept when present (COALESCE); the rest are
     * Wikidata-only.
     */
    private const array WIKIDATA_TABLES = ['cultural_pois', 'food_pois'];

    /**
     * Persistent enrichment cache (ADR-041), in a stable schema that survives the
     * tourism swap. Only Q-IDs absent or older than the TTL are re-queried, so
     * enrichment resumes after a crash and the daily refresh reuses the cache
     * (Wikidata's effective cadence stays monthly). Scratch tables hold the
     * per-run candidate / fetched sets; both live here and are dropped each run.
     */
    private const string CACHE_SCHEMA = 'provisioner';

    private const string CACHE_TABLE = 'provisioner.wikidata_cache';

    private const string CANDIDATES_TABLE = 'provisioner.wikidata_candidates';

    private const string FETCH_TABLE = 'provisioner.wikidata_fetch';

    private HttpClientInterface $httpClient;

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        private string $fluxUrl,
        private DataTourismeMapper $mapper = new DataTourismeMapper(),
        ?HttpClientInterface $httpClient = null,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 1800.0,
        private WikidataEnricher $enricher = new WikidataEnricher(),
        private string $locale = 'fr',
        private int $cacheTtlDays = 30,
    ) {
        // Scoped to the DataTourisme origin (SSRF policy, see CLAUDE.md). Cap the
        // total transfer (ADR-041) so a stalled flux endpoint fails fast rather
        // than blocking the run; `timeout` is the per-chunk idle wait.
        $this->httpClient = $httpClient ?? ScopingHttpClient::forBaseUri(
            HttpClient::create([
                'max_redirects' => 2,
                'timeout' => 120.0,
                'max_duration' => $this->timeoutSeconds,
            ]),
            'https://diffuseur.datatourisme.fr/',
        );
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @throws ImportFailedException
     */
    public function run(string $workDir): void
    {
        $zipPath = $workDir.'/datatourisme-flux.zip';
        $this->download($zipPath);
        ['files' => $copyFiles, 'wikidataIds' => $wikidataIds] = $this->extract($zipPath, $workDir);
        $this->load($copyFiles);
        $this->enrich($workDir, $wikidataIds);
        $this->swap();
    }

    /**
     * @throws ImportFailedException
     */
    public function download(string $zipPath): void
    {
        $handle = fopen($zipPath, 'w');
        if (false === $handle) {
            throw new ImportFailedException(\sprintf('Cannot open "%s" for writing', $zipPath));
        }

        try {
            $response = $this->httpClient->request('GET', $this->fluxUrl);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ImportFailedException(\sprintf('DataTourisme flux download failed with HTTP %d', $status));
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if (false === fwrite($handle, $chunk->getContent())) {
                    throw new ImportFailedException(\sprintf('Failed to write the flux to "%s"', $zipPath));
                }
            }
        } catch (HttpClientExceptionInterface $httpClientException) {
            fclose($handle);

            throw new ImportFailedException(\sprintf('DataTourisme flux download failed: %s', $httpClientException->getMessage()), 0, $httpClientException);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Streams the flux ZIP and writes one text-format COPY file per table,
     * collecting the distinct Wikidata Q-IDs seen on enrichable rows for the
     * post-load enrichment pass.
     *
     * @return array{files: array<string, string>, wikidataIds: list<string>}
     *
     * @throws ImportFailedException
     */
    private function extract(string $zipPath, string $workDir): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new ImportFailedException(\sprintf('Cannot open the flux ZIP "%s"', $zipPath));
        }

        $handles = [];
        $files = [];
        foreach (array_keys(self::TABLE_COLUMNS) as $table) {
            $path = \sprintf('%s/tourism-%s.copy', $workDir, $table);
            $handle = fopen($path, 'w');
            if (false === $handle) {
                throw new ImportFailedException(\sprintf('Cannot open COPY file "%s"', $path));
            }

            $handles[$table] = $handle;
            $files[$table] = $path;
        }

        $heads = ['cultural' => 'cultural_pois', 'food' => 'food_pois', 'accommodation' => 'accommodations', 'event' => 'events'];

        /** @var array<string, true> $wikidataIds */
        $wikidataIds = [];

        for ($i = 0, $n = $zip->numFiles; $i < $n; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name || !str_starts_with($name, 'objects/') || !str_ends_with($name, '.json')) {
                continue;
            }

            $stream = $zip->getStream($name);
            if (false === $stream) {
                continue;
            }

            $contents = stream_get_contents($stream);
            fclose($stream);
            if (false === $contents) {
                continue;
            }

            $object = json_decode($contents, true);
            if (!\is_array($object)) {
                continue;
            }

            /** @var array<string, mixed> $object */
            $row = $this->mapper->map($object);
            if (null === $row) {
                continue;
            }

            $table = $heads[$row['head']];
            fwrite($handles[$table], $this->copyLine($table, $row));

            if (\in_array($table, self::WIKIDATA_TABLES, true) && null !== $row['wikidata']) {
                $wikidataIds[$row['wikidata']] = true;
            }
        }

        foreach ($handles as $handle) {
            fclose($handle);
        }

        $zip->close();

        return ['files' => $files, 'wikidataIds' => array_keys($wikidataIds)];
    }

    /**
     * @phpstan-param Row $row
     */
    private function copyLine(string $table, array $row): string
    {
        $geom = \sprintf('SRID=4326;POINT(%.7F %.7F)', $row['lon'], $row['lat']);
        $tags = json_encode(['type' => $row['type']], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        $values = match ($table) {
            'cultural_pois', 'food_pois' => [$row['id'], $row['name'], $row['category'], $row['openingHours'], $row['description'], $row['wikidata'], $tags, $geom],
            'accommodations' => [$row['id'], $row['name'], $row['category'], $row['capacity'], $row['price'], $row['description'], $tags, $geom],
            'events' => [$row['id'], $row['name'], $row['category'], $row['startDate'], $row['endDate'], null, $row['description'], $row['price'], $tags, $geom],
            default => [],
        };

        return implode("\t", array_map($this->copyValue(...), $values))."\n";
    }

    private function copyValue(string|int|float|null $value): string
    {
        if (null === $value) {
            return '\N';
        }

        $string = \is_string($value) ? $value : (string) $value;

        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $string);
    }

    /**
     * @param array<string, string> $copyFiles
     *
     * @throws ImportFailedException
     */
    private function load(array $copyFiles): void
    {
        $ddl = \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s;', self::STAGING_SCHEMA);
        foreach (self::STAGING_DDL as $table => $columns) {
            $ddl .= \sprintf(' CREATE TABLE %s.%s (%s);', self::STAGING_SCHEMA, $table, $columns);
        }

        $this->runProcess(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $ddl], 'psql create tourism staging');

        foreach ($copyFiles as $table => $path) {
            $columns = implode(', ', self::TABLE_COLUMNS[$table]);
            $copy = \sprintf("\\copy %s.%s (%s) FROM '%s'", self::STAGING_SCHEMA, $table, $columns, $path);
            $this->runProcess(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $copy], \sprintf('psql copy %s', $table));
        }

        foreach (array_keys(self::TABLE_COLUMNS) as $table) {
            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf('CREATE INDEX ON %s.%s USING gist (geom);', self::STAGING_SCHEMA, $table),
            ], \sprintf('psql index %s', $table));
        }

        // Date-range index for the events read path (matches Version20260616120000);
        // recreated in staging so the atomic swap doesn't drop it.
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('CREATE INDEX ON %s.events (start_date, end_date);', self::STAGING_SCHEMA),
        ], 'psql index events dates');

        // Provisioning metadata (refresh timestamp + per-table counts), surfaced
        // by /api/health so operators see the DataTourisme index freshness.
        $counts = implode(', ', array_map(
            static fn (string $table): string => \sprintf("'%1\$s', (SELECT count(*) FROM %2\$s.%1\$s)", $table, self::STAGING_SCHEMA),
            array_keys(self::TABLE_COLUMNS),
        ));
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('CREATE TABLE %1$s.metadata AS SELECT now() AS refreshed_at, jsonb_build_object(%2$s) AS feature_counts;', self::STAGING_SCHEMA, $counts),
        ], 'psql build tourism metadata');
    }

    /**
     * Enriches the staged Wikidata-bearing tables from the persistent cache
     * (ADR-040/041). Runs after {@see load} (rows are in staging) and before
     * {@see swap} so the enrichment lands in the dataset that goes live
     * atomically.
     *
     * Resumable + memory-bounded: only the Q-IDs absent from the cache or older
     * than the TTL are queried from Wikidata, streamed one batch at a time into a
     * COPY file (never the whole set in memory), upserted into the cache, then
     * the live tables are UPDATEd by joining the cache — so previously cached
     * Q-IDs enrich the fresh staging tables without any network call.
     *
     * Best-effort: a Wikidata outage leaves the missing Q-IDs uncached (retried
     * next run) and the rows enriched only from whatever the cache already held.
     *
     * @param list<string> $wikidataIds
     *
     * @throws ImportFailedException
     */
    private function enrich(string $workDir, array $wikidataIds): void
    {
        if ([] === $wikidataIds) {
            return;
        }

        // Self-contained: create the cache schema/table if the API migration has
        // not run on this DB, plus fresh per-run scratch tables (drop any leftover
        // from a prior crash — they live in the stable schema, not the swap).
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                'CREATE SCHEMA IF NOT EXISTS %1$s; CREATE TABLE IF NOT EXISTS %2$s (qid text PRIMARY KEY, payload jsonb NOT NULL, fetched_at timestamptz NOT NULL); DROP TABLE IF EXISTS %3$s, %4$s; CREATE TABLE %3$s (qid text); CREATE TABLE %4$s (qid text, payload jsonb);',
                self::CACHE_SCHEMA,
                self::CACHE_TABLE,
                self::CANDIDATES_TABLE,
                self::FETCH_TABLE,
            ),
        ], 'psql prepare wikidata cache');

        // Load the candidate Q-IDs, then export those missing/stale in the cache.
        $candidatesPath = $workDir.'/tourism-wikidata-candidates.copy';
        $this->writeColumn($candidatesPath, $wikidataIds);
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf("\\copy %s (qid) FROM '%s'", self::CANDIDATES_TABLE, $candidatesPath),
        ], 'psql copy wikidata candidates');

        $missingPath = $workDir.'/tourism-wikidata-missing.copy';
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                "\\copy (SELECT cand.qid FROM %1\$s cand WHERE NOT EXISTS (SELECT 1 FROM %2\$s c WHERE c.qid = cand.qid AND c.fetched_at > now() - make_interval(days => %3\$d))) TO '%4\$s'",
                self::CANDIDATES_TABLE,
                self::CACHE_TABLE,
                $this->cacheTtlDays,
                $missingPath,
            ),
        ], 'psql select missing wikidata');

        // Query only the missing Q-IDs, streaming each batch straight to the COPY
        // file, then upsert them into the cache (negative cache for no-data Q-IDs).
        $missing = $this->readColumn($missingPath);
        if ([] !== $missing) {
            $fetchPath = $workDir.'/tourism-wikidata-fetch.copy';
            $handle = fopen($fetchPath, 'w');
            if (false === $handle) {
                throw new ImportFailedException(\sprintf('Cannot open enrichment COPY file "%s"', $fetchPath));
            }

            foreach ($this->enricher->enrich($missing, $this->locale) as $row) {
                $payload = json_encode((object) ($row['enrichment'] ?? []), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
                fwrite($handle, $this->copyValue($row['qid'])."\t".$this->copyValue($payload)."\n");
            }

            fclose($handle);

            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf("\\copy %s (qid, payload) FROM '%s'", self::FETCH_TABLE, $fetchPath),
            ], 'psql copy wikidata fetched');

            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf(
                    'INSERT INTO %1$s (qid, payload, fetched_at) SELECT qid, payload, now() FROM %2$s ON CONFLICT (qid) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at;',
                    self::CACHE_TABLE,
                    self::FETCH_TABLE,
                ),
            ], 'psql upsert wikidata cache');
        }

        // Enrich the live tables from the cache (fresh + previously cached). The
        // source fields (description, opening_hours) win when present; the rest are
        // Wikidata-only.
        foreach (self::WIKIDATA_TABLES as $target) {
            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf(
                    "UPDATE %1\$s.%2\$s t SET description = COALESCE(t.description, c.payload->>'description'), opening_hours = COALESCE(t.opening_hours, c.payload->>'openingHours'), website = c.payload->>'website', image_url = c.payload->>'imageUrl', wikipedia_url = c.payload->>'wikipediaUrl' FROM %3\$s c WHERE t.wikidata = c.qid;",
                    self::STAGING_SCHEMA,
                    $target,
                    self::CACHE_TABLE,
                ),
            ], \sprintf('psql enrich %s', $target));
        }

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP TABLE IF EXISTS %s, %s;', self::CANDIDATES_TABLE, self::FETCH_TABLE),
        ], 'psql drop wikidata scratch tables');
    }

    /**
     * Writes a single-column COPY file (one escaped value per line).
     *
     * @param list<string> $values
     *
     * @throws ImportFailedException
     */
    private function writeColumn(string $path, array $values): void
    {
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new ImportFailedException(\sprintf('Cannot open COPY file "%s"', $path));
        }

        foreach ($values as $value) {
            fwrite($handle, $this->copyValue($value)."\n");
        }

        fclose($handle);
    }

    /**
     * Reads a single-column psql COPY-out file back into a list.
     *
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

    /**
     * @throws ImportFailedException
     */
    private function swap(): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; ALTER SCHEMA %2$s RENAME TO %1$s;', self::LIVE_SCHEMA, self::STAGING_SCHEMA),
        ], 'psql tourism swap');
    }

    /**
     * @param list<string> $command
     *
     * @throws ImportFailedException
     */
    private function runProcess(array $command, string $label): void
    {
        $process = ($this->processFactory)($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $processTimedOutException) {
            throw new ImportFailedException(\sprintf('%s timed out after %.1fs', $label, $this->timeoutSeconds), 0, $processTimedOutException);
        }

        if (!$process->isSuccessful()) {
            throw new ImportFailedException(\sprintf("%s failed (exit %s).\nCommand: %s\nStderr: %s", $label, (string) $process->getExitCode(), implode(' ', $command), $process->getErrorOutput()));
        }
    }
}
