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

    private const string WIKIDATA_ENRICH_TABLE = 'wikidata_enrich';

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
    ) {
        // Scoped to the DataTourisme origin (SSRF policy, see CLAUDE.md).
        $this->httpClient = $httpClient ?? ScopingHttpClient::forBaseUri(
            HttpClient::create(['max_redirects' => 2, 'timeout' => $this->timeoutSeconds]),
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
     * Enriches the staged Wikidata-bearing tables from Wikidata (ADR-040): batch
     * SPARQL over the distinct Q-IDs seen during extraction, then a single
     * UPDATE per table joining on the `wikidata` column. Runs after {@see load}
     * (rows are in staging) and before {@see swap} so the enrichment lands in the
     * dataset that goes live atomically.
     *
     * Best-effort: an empty enrichment (Wikidata outage handled in
     * {@see WikidataEnricher}) simply leaves the source rows untouched.
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

        $enrichments = $this->enricher->enrich($wikidataIds, $this->locale);
        if ([] === $enrichments) {
            return;
        }

        $path = $workDir.'/tourism-wikidata.copy';
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new ImportFailedException(\sprintf('Cannot open enrichment COPY file "%s"', $path));
        }

        foreach ($enrichments as $qId => $entry) {
            fwrite($handle, implode("\t", array_map($this->copyValue(...), [
                $qId,
                $entry['description'] ?? null,
                $entry['openingHours'] ?? null,
                $entry['website'] ?? null,
                $entry['imageUrl'] ?? null,
                $entry['wikipediaUrl'] ?? null,
            ]))."\n");
        }

        fclose($handle);

        $table = \sprintf('%s.%s', self::STAGING_SCHEMA, self::WIKIDATA_ENRICH_TABLE);
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('CREATE TABLE %s (wikidata text PRIMARY KEY, description text, opening_hours text, website text, image_url text, wikipedia_url text);', $table),
        ], 'psql create wikidata enrich table');

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf("\\copy %s (wikidata, description, opening_hours, website, image_url, wikipedia_url) FROM '%s'", $table, $path),
        ], 'psql copy wikidata enrich');

        foreach (self::WIKIDATA_TABLES as $target) {
            // description / opening_hours come from DataTourisme first, so keep the
            // source value when present; the other columns are Wikidata-only.
            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf(
                    'UPDATE %1$s.%2$s t SET description = COALESCE(t.description, e.description), opening_hours = COALESCE(t.opening_hours, e.opening_hours), website = e.website, image_url = e.image_url, wikipedia_url = e.wikipedia_url FROM %3$s e WHERE t.wikidata = e.wikidata;',
                    self::STAGING_SCHEMA,
                    $target,
                    $table,
                ),
            ], \sprintf('psql enrich %s', $target));
        }

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP TABLE %s;', $table),
        ], 'psql drop wikidata enrich table');
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
            throw new ImportFailedException(\sprintf('%s failed (exit %s): %s', $label, (string) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
