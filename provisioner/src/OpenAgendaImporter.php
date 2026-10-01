<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Process;
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
 */
final readonly class OpenAgendaImporter implements EventsRefreshSourceInterface
{
    private const string SOURCE = 'openagenda';

    /**
     * Staging schema for the standalone events refresh (ADR-051 §4). Fixed, not per-zone:
     * the refresh downloads the national export once and clips it to each open zone in
     * turn, so one schema is loaded and reused. The provisioner lock serialises runs, and
     * a `DROP ... IF EXISTS` at load time clears any schema a crashed run left behind.
     */
    private const string REFRESH_SCHEMA = 'openagenda_events_refresh';

    private FeedDownloader $downloader;

    private ProcessRunner $processes;

    private ZoneGeometry $zoneGeometry;

    private EventsStaging $events;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        string $exportUrl,
        private OpenAgendaMapper $mapper = new OpenAgendaMapper(),
        ?HttpClientInterface $httpClient = null,
        ?\Closure $processFactory = null,
        float $timeoutSeconds = 1800.0,
    ) {
        parse_str((string) parse_url($exportUrl, \PHP_URL_QUERY), $query);
        $apiKey = $query['apikey'] ?? '';
        $this->downloader = new FeedDownloader(
            // Cap the total transfer (ADR-041) so a stalled endpoint fails fast rather than
            // blocking the run; `timeout` is the per-chunk idle wait.
            $httpClient ?? ScopedHttpClient::create('https://public.opendatasoft.com/', ['timeout' => 120.0, 'max_duration' => $timeoutSeconds]),
            $exportUrl,
            'OpenAgenda export',
            // `?apikey=` is there only when a private portal sets one (EnvImporters).
            \is_string($apiKey) ? $apiKey : '',
        );
        $this->processes = new ProcessRunner($processFactory, $timeoutSeconds);
        $this->zoneGeometry = new ZoneGeometry($this->processes);
        // Events are perishable, so promotion is upsert-and-purge, not the append-only
        // anti-join {@see ZonePromotion} runs for places (ADR-051 §4).
        $this->events = new EventsStaging(self::SOURCE, $this->processes);
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
        return Sql::zoneSchema('openagenda_staging', $zoneSlug);
    }

    /**
     * Downloads, stages and promotes the events covered by the zone.
     *
     * Unlike DataTourisme, OpenAgenda does not refresh `tourism.metadata`: events feed a
     * single table and DataTourisme owns that single-row snapshot. When both sources run,
     * OpenAgenda is promoted before the DataTourisme finish, so the DataTourisme metadata
     * refresh counts OpenAgenda's events in the live totals.
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
        $this->downloader->download($jsonlPath);
        $copyFile = $this->extract($jsonlPath, $workDir);
        $this->events->load($staging, $copyFile);

        // Same precondition as DataTourisme (#885): the geometry, not the OSM exit code.
        if (!$this->zoneGeometry->exists($workDir.'/openagenda-zone-geometry.tsv', $zoneSlug)) {
            $this->dropStaging($staging);

            return false;
        }

        $this->events->promote($zoneSlug, $staging, $today);
        $this->dropStaging($staging);

        return true;
    }

    public function stageEventsForRefresh(string $workDir): string
    {
        $jsonlPath = $workDir.'/openagenda-export.jsonl';
        $this->downloader->download($jsonlPath);
        $copyFile = $this->extract($jsonlPath, $workDir);
        $this->events->load(self::REFRESH_SCHEMA, $copyFile);

        return self::REFRESH_SCHEMA;
    }

    public function promoteEventsForZone(string $stagingSchema, string $zone, string $today): void
    {
        $this->events->promote($zone, $stagingSchema, $today);
    }

    public function dropRefreshStaging(string $stagingSchema): void
    {
        $this->dropStaging($stagingSchema);
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

                fwrite($out, $this->events->line([
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'start_date' => $row['startDate'],
                    'end_date' => $row['endDate'],
                    'url' => $row['url'],
                    'description' => $row['description'],
                    'price_min' => $row['priceMin'],
                    'tags' => $row['tags'],
                    'lat' => $row['lat'],
                    'lon' => $row['lon'],
                ]));
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $copyFile;
    }

    /**
     * @throws ImportFailedException
     */
    private function dropStaging(string $stagingSchema): void
    {
        $this->processes->psql(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE;', $stagingSchema), 'psql drop openagenda staging schema');
    }
}
