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
        'cultural_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'food_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'accommodations' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, capacity int, price numeric(10, 2), description text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'events' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, start_date date, end_date date, url text, description text, price_min numeric(10, 2), tags jsonb, geom geometry(Point, 4326) NOT NULL',
    ];

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
        $copyFiles = $this->extract($zipPath, $workDir);
        $this->load($copyFiles);
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
     * Streams the flux ZIP and writes one text-format COPY file per table.
     *
     * @return array<string, string> table name => COPY file path
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
        }

        foreach ($handles as $handle) {
            fclose($handle);
        }

        $zip->close();

        return $files;
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
