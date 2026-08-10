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
 * Imports OpenAgenda events into the local-first `tourism.events` layer (ADR-051),
 * as the second events source alongside DataTourisme.
 *
 * Flow (a leaner mirror of {@see DataTourismeImporter}, events being the only table
 * OpenAgenda feeds): stream-download the Opendatasoft JSONL export line by line,
 * {@see OpenAgendaMapper map} each record to an event row, write the rows to a COPY
 * file (constant memory regardless of the record count), bulk-load them into a
 * per-zone staging schema via psql COPY, then upsert-and-purge the events clipped to
 * the zone geometry ({@see EventsPromotion}: `ON CONFLICT (id) DO UPDATE` the mutable
 * fields, then drop the events that have passed). Unlike the append-only place layers,
 * events are perishable, so a refresh updates a moved date in place and purges what has
 * ended (ADR-051 §4). A failed import leaves the previous dataset intact, and one source
 * failing never touches the other's rows (ADR-041): OpenAgenda writes `source='openagenda'`,
 * the cross-source dedup happening at read time in `App\EventSource\EventSourceRegistry`.
 *
 * The export is **national** while a run opens **one zone**, so the promotion is
 * clipped to that zone's registry geometry (ADR-049 §1), exactly as DataTourisme is.
 * That geometry is produced by the OSM step, so promotion runs after it; with no
 * geometry there is nothing to clip against and the run skips rather than promoting
 * a whole country's events under one zone's name.
 *
 * Rows are emitted in PostgreSQL text COPY format (`\N` = NULL, tab-separated,
 * backslash-escaped); `geom` receives EWKT (`SRID=4326;POINT(lon lat)`). The DB
 * connection comes from the libpq environment (PG*), inherited by psql.
 *
 * @phpstan-import-type Row from OpenAgendaMapper
 */
final readonly class OpenAgendaImporter implements EventsRefreshSourceInterface
{
    private const string LIVE_SCHEMA = 'tourism';

    private const string SOURCE = 'openagenda';

    /**
     * Staging schema for the standalone events refresh (ADR-051 §4). Fixed, not per-zone:
     * the refresh downloads the national export once and clips it to each open zone in
     * turn, so one schema is loaded and reused. The provisioner lock serialises runs, and
     * a `DROP ... IF EXISTS` at load time clears any schema a crashed run left behind.
     */
    private const string REFRESH_SCHEMA = 'openagenda_events_refresh';

    /**
     * COPY column order for the events table. `source` is written explicitly as
     * 'openagenda' (the multi-source column of Version20260810120000, ADR-051);
     * `geom` always comes last and is fed EWKT.
     *
     * @var list<string>
     */
    private const array EVENT_COLUMNS = ['id', 'name', 'category', 'start_date', 'end_date', 'url', 'description', 'price_min', 'source', 'tags', 'geom'];

    /**
     * Same events staging DDL as {@see DataTourismeImporter}: a subset of the live
     * tourism.events the promotion completes with the `zone` / `last_seen_at`
     * provenance pair.
     */
    private const string EVENTS_DDL = "id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, start_date date, end_date date, url text, description text, price_min numeric(10, 2), source text NOT NULL DEFAULT 'openagenda', tags jsonb, geom geometry(Point, 4326) NOT NULL";

    private HttpClientInterface $httpClient;

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    private EventsPromotion $promotion;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        private string $exportUrl,
        private OpenAgendaMapper $mapper = new OpenAgendaMapper(),
        ?HttpClientInterface $httpClient = null,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 1800.0,
    ) {
        // Scoped to the Opendatasoft origin (SSRF policy, see CLAUDE.md). Cap the
        // total transfer (ADR-041) so a stalled endpoint fails fast rather than
        // blocking the run; `timeout` is the per-chunk idle wait.
        $this->httpClient = $httpClient ?? ScopingHttpClient::forBaseUri(
            HttpClient::create([
                'max_redirects' => 2,
                'timeout' => 120.0,
                'max_duration' => $this->timeoutSeconds,
            ]),
            'https://public.opendatasoft.com/',
        );
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
        // Events are perishable, so promotion is upsert-and-purge, not the append-only
        // anti-join {@see ZonePromotion} runs for places (ADR-051 §4).
        $this->promotion = new EventsPromotion(self::SOURCE, self::LIVE_SCHEMA);
    }

    public function label(): string
    {
        return self::SOURCE;
    }

    /**
     * Staging schema for a zone: derived, never configured, and namespaced to the
     * source so it can never collide with the DataTourisme staging schema of the
     * same run.
     */
    public static function stagingSchema(string $zoneSlug): string
    {
        return 'openagenda_staging_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($zoneSlug));
    }

    /**
     * Downloads, stages and promotes the events covered by the zone.
     *
     * @param string $today the purge boundary as `YYYY-MM-DD`, computed by the caller with
     *                      an explicit timezone (see {@see EventsPromotion})
     *
     * @return bool false when the zone has no registry geometry to clip against, so nothing
     *              was promoted — a skip, not a failure
     *
     * @throws ImportFailedException
     */
    public function run(string $workDir, string $zoneSlug, string $today): bool
    {
        $staging = self::stagingSchema($zoneSlug);

        $jsonlPath = $workDir.'/openagenda-export.jsonl';
        $this->download($jsonlPath);
        $copyFile = $this->extract($jsonlPath, $workDir);
        $this->load($staging, $copyFile);

        // Promotion clips to the zone's registry geometry; with none it would promote
        // exactly nothing and report success silently, which is worse than skipping.
        // Same precondition as DataTourisme (#885): the geometry, not the OSM exit code.
        if (!$this->zoneHasGeometry($workDir, $zoneSlug)) {
            $this->dropStaging($staging);

            return false;
        }

        $this->promote($zoneSlug, $staging, $today);
        $this->dropStaging($staging);

        return true;
    }

    public function stageEventsForRefresh(string $workDir): string
    {
        $jsonlPath = $workDir.'/openagenda-export.jsonl';
        $this->download($jsonlPath);
        $copyFile = $this->extract($jsonlPath, $workDir);
        $this->load(self::REFRESH_SCHEMA, $copyFile);

        return self::REFRESH_SCHEMA;
    }

    public function promoteEventsForZone(string $stagingSchema, string $zone, string $today): void
    {
        $this->promote($zone, $stagingSchema, $today);
    }

    public function dropRefreshStaging(string $stagingSchema): void
    {
        $this->dropStaging($stagingSchema);
    }

    /**
     * @throws ImportFailedException
     */
    public function download(string $jsonlPath): void
    {
        $handle = fopen($jsonlPath, 'w');
        if (false === $handle) {
            throw new ImportFailedException(\sprintf('Cannot open "%s" for writing', $jsonlPath));
        }

        try {
            $response = $this->httpClient->request('GET', $this->exportUrl);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ImportFailedException(\sprintf('OpenAgenda export download failed with HTTP %d', $status));
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if (false === fwrite($handle, $chunk->getContent())) {
                    throw new ImportFailedException(\sprintf('Failed to write the export to "%s"', $jsonlPath));
                }
            }
        } catch (HttpClientExceptionInterface $httpClientException) {
            fclose($handle);

            throw new ImportFailedException(\sprintf('OpenAgenda export download failed: %s', $httpClientException->getMessage()), 0, $httpClientException);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Streams the JSONL export line by line and writes the events COPY file. One JSON
     * object per line keeps memory constant regardless of the record count.
     *
     * @return string the events COPY file path
     *
     * @throws ImportFailedException
     */
    private function extract(string $jsonlPath, string $workDir): string
    {
        $in = fopen($jsonlPath, 'r');
        if (false === $in) {
            throw new ImportFailedException(\sprintf('Cannot open the export "%s"', $jsonlPath));
        }

        $copyFile = $workDir.'/openagenda-events.copy';
        $out = fopen($copyFile, 'w');
        if (false === $out) {
            fclose($in);

            throw new ImportFailedException(\sprintf('Cannot open COPY file "%s"', $copyFile));
        }

        try {
            while (false !== ($line = fgets($in))) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                $record = json_decode($line, true);
                if (!\is_array($record)) {
                    continue;
                }

                /** @var array<string, mixed> $record */
                $row = $this->mapper->map($record);
                if (null === $row) {
                    continue;
                }

                fwrite($out, $this->copyLine($row));
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $copyFile;
    }

    /**
     * @phpstan-param Row $row
     */
    private function copyLine(array $row): string
    {
        $geom = \sprintf('SRID=4326;POINT(%.7F %.7F)', $row['lon'], $row['lat']);
        $tags = json_encode($row['tags'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        $values = [$row['id'], $row['name'], $row['category'], $row['startDate'], $row['endDate'], $row['url'], $row['description'], $row['priceMin'], self::SOURCE, $tags, $geom];

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
     * @throws ImportFailedException
     */
    private function load(string $stagingSchema, string $copyFile): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s; CREATE TABLE %1$s.events (%2$s);', $stagingSchema, self::EVENTS_DDL),
        ], 'psql create openagenda staging');

        $columns = implode(', ', self::EVENT_COLUMNS);
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf("\\copy %s.events (%s) FROM '%s'", $stagingSchema, $columns, $copyFile),
        ], 'psql copy events');

        // Serves the zone clip: the promotion tests every staged row against the zone
        // polygon with ST_Covers.
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('CREATE INDEX ON %s.events USING gist (geom);', $stagingSchema),
        ], 'psql index events');
    }

    /**
     * @throws ImportFailedException
     */
    private function zoneHasGeometry(string $workDir, string $zoneSlug): bool
    {
        $path = $workDir.'/openagenda-zone-geometry.tsv';
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                "\\copy (SELECT count(*) FROM osm.zones WHERE slug = %s AND geom IS NOT NULL) TO '%s'",
                ZonePromotion::literal($zoneSlug),
                $path,
            ),
        ], 'psql check zone geometry');

        $contents = is_file($path) ? file_get_contents($path) : false;

        return \is_string($contents) && 0 < (int) trim($contents);
    }

    /**
     * Upserts the staged events covered by the zone into the live schema and purges past
     * events, in one transaction (ADR-051 §4). Unlike DataTourisme, OpenAgenda does not
     * refresh `tourism.metadata`: events feed a single table and DataTourisme owns that
     * single-row snapshot. When both sources run, OpenAgenda is promoted before the
     * DataTourisme finish, so the DataTourisme metadata refresh counts OpenAgenda's events
     * in the live totals.
     *
     * @throws ImportFailedException
     */
    private function promote(string $zoneSlug, string $stagingSchema, string $today): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c', $this->promotion->reportDdl(),
        ], 'psql prepare promotion report');

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c',
            $this->promotion->sql($zoneSlug, $stagingSchema, $today),
        ], \sprintf('psql upsert+purge openagenda events zone %s', $zoneSlug));
    }

    /**
     * @throws ImportFailedException
     */
    private function dropStaging(string $stagingSchema): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %s CASCADE;', $stagingSchema),
        ], 'psql drop openagenda staging schema');
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
