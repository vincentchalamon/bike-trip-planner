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
 * Flow, behind a transactional per-zone promotion (same shape as
 * {@see PostgisImporter}): download the flux ZIP, stream its `objects/` JSON-LD files
 * one by one, {@see DataTourismeMapper map} each to a cultural-POI / accommodation /
 * event row, write the rows to per-table COPY files (constant memory regardless of
 * the ~390k objects), bulk-load them into a staging schema scoped to the zone via
 * psql COPY, then `INSERT ... SELECT` the ids the live tables do not already hold. A
 * failed import leaves the previous dataset intact.
 *
 * The flux is **national** while a run opens **one zone**, so the promotion is clipped
 * to that zone's registry geometry (ADR-049 §1): without the clip, opening Brittany
 * would import the whole country's places and label them Brittany. Places outside every
 * opened zone are simply not promoted; the zone that covers them picks them up when it
 * is opened.
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
    private const string LIVE_SCHEMA = 'tourism';

    /**
     * Target tables and their COPY column order. `geom` always comes last and is
     * fed EWKT. Must match Version20260616120000 / Version20260616140000 (the
     * live-schema bootstraps) plus Version20260617130000 (`website` on the POI
     * tables) and Version20260803120000 (the accommodation contact columns).
     *
     * `image_url` / `wikipedia_url` are absent on purpose: they exist in the DDL
     * but are written by the Wikidata pass alone, never by the flux.
     *
     * @var array<string, list<string>>
     */
    private const array TABLE_COLUMNS = [
        'cultural_pois' => ['id', 'name', 'category', 'opening_hours', 'description', 'website', 'wikidata', 'tags', 'geom'],
        'food_pois' => ['id', 'name', 'category', 'opening_hours', 'description', 'website', 'wikidata', 'tags', 'geom'],
        'accommodations' => ['id', 'name', 'category', 'capacity', 'price', 'description', 'opening_hours', 'website', 'phone', 'wikidata', 'tags', 'geom'],
        'events' => ['id', 'name', 'category', 'start_date', 'end_date', 'url', 'description', 'price_min', 'tags', 'geom'],
    ];

    private const array STAGING_DDL = [
        'cultural_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, website text, image_url text, wikipedia_url text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'food_pois' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, opening_hours text, description text, website text, image_url text, wikipedia_url text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'accommodations' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, capacity int, price numeric(10, 2), description text, opening_hours text, website text, phone text, image_url text, wikipedia_url text, wikidata text, tags jsonb, geom geometry(Point, 4326) NOT NULL',
        'events' => 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, start_date date, end_date date, url text, description text, price_min numeric(10, 2), tags jsonb, geom geometry(Point, 4326) NOT NULL',
    ];

    /**
     * Tables carrying a `wikidata` Q-ID column, enriched from Wikidata via the
     * shared {@see WikidataEnrichmentPass} after load and before the swap.
     * `accommodations` joined the list with its Q-ID column (#872), which is also
     * what lets NearbyNameDeduplicator pair an OSM and a DataTourisme lodging.
     *
     * @var list<string>
     */
    private const array WIKIDATA_TABLES = ['cultural_pois', 'food_pois', 'accommodations'];

    /**
     * Completeness metrics recorded per table (issue #877): metric key => the text
     * column whose presence is measured. Events carry their link in `url` and have
     * no opening hours (they have a date range instead).
     *
     * @var array<string, array<string, string>>
     */
    private const array COMPLETENESS_METRICS = [
        'cultural_pois' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours'],
        'food_pois' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours'],
        'accommodations' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours', 'with_phone' => 'phone'],
        'events' => ['named' => 'name', 'with_link' => 'url'],
    ];

    /**
     * Tables also broken down per `category`; see PostgisImporter for the rationale.
     *
     * @var list<string>
     */
    private const array COMPLETENESS_BY_CATEGORY = ['accommodations'];

    /**
     * Live tables mapped to the predicate matching a live row `l` to a staging row `s`.
     * The flux carries a stable `id` per object, so identity is that id alone.
     *
     * @var array<string, string>
     */
    private const array IDENTITY = [
        'cultural_pois' => 'l.id = s.id',
        'food_pois' => 'l.id = s.id',
        'accommodations' => 'l.id = s.id',
        'events' => 'l.id = s.id',
    ];

    private HttpClientInterface $httpClient;

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    private WikidataEnrichmentPass $enrichmentPass;

    private ZonePromotion $promotion;

    private PlaceEnrichmentPass $placeEnrichmentPass;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        private string $fluxUrl,
        private DataTourismeMapper $mapper = new DataTourismeMapper(),
        ?HttpClientInterface $httpClient = null,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 1800.0,
        WikidataEnricher $enricher = new WikidataEnricher(),
        string $locale = 'fr',
        int $cacheTtlDays = 30,
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
        $this->enrichmentPass = new WikidataEnrichmentPass($this->processFactory, $enricher, $locale, $cacheTtlDays, $this->timeoutSeconds);
        $this->promotion = new ZonePromotion('datatourisme', self::LIVE_SCHEMA, self::IDENTITY);
        // Boundaries come from the live `osm` schema: the flux carries no administrative
        // geometry of its own, and the zone's own boundaries were promoted by the OSM step
        // that always runs first.
        $this->placeEnrichmentPass = new PlaceEnrichmentPass(
            source: 'datatourisme',
            identity: 'a.id',
            exemptCategories: [],
            boundariesSchema: 'osm',
            processFactory: $this->processFactory,
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    /**
     * Staging schema for a zone: derived, never configured, so two runs on different
     * zones can never collide on a shared name.
     */
    public static function stagingSchema(string $zoneSlug): string
    {
        return 'tourism_staging_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($zoneSlug));
    }

    /**
     * Downloads and loads the flux into the zone's staging schema, without promoting it.
     *
     * Split from {@see promote()} for #885. The flux is the **curated** source — over
     * 124 240 accommodations, not one has an empty name — so the OSM side must be able to
     * borrow a name from it, and that means the flux has to be staged *before* the OSM
     * gate decides what to reject. Promotion still comes last, because it clips to the
     * zone geometry that only the OSM import produces: staging first, promoting last, is
     * the only ordering that satisfies both.
     *
     * @throws ImportFailedException
     */
    public function stage(string $workDir, string $zoneSlug): string
    {
        $staging = self::stagingSchema($zoneSlug);

        $zipPath = $workDir.'/datatourisme-flux.zip';
        $this->download($zipPath);
        $copyFiles = $this->extract($zipPath, $workDir);
        $this->load($staging, $copyFiles);
        $this->enrichmentPass->run($workDir, $staging, self::WIKIDATA_TABLES);

        return $staging;
    }

    /**
     * Gates the staged flux and promotes what survives into the live schema.
     *
     * @return bool false when the zone has no registry geometry to clip against, so nothing
     *              was promoted — a skip, not a failure
     *
     * @throws ImportFailedException
     */
    public function finish(string $workDir, string $zoneSlug): bool
    {
        $staging = self::stagingSchema($zoneSlug);

        // The promotion clips to the zone's registry geometry, so with no geometry it would
        // promote exactly nothing and report success — silently, which is worse than
        // refusing. That happens when the OSM step failed before writing the registry row,
        // and also when it succeeded but its clipped extract yielded no boundary at all
        // (#880). The precondition is therefore the geometry, not the sibling step's exit
        // code: gating on the OSM outcome would make a failed OSM download also block a
        // DataTourisme refresh for a zone that is already open, which is precisely the
        // cross-source coupling ADR-041 forbids.
        if (!$this->zoneHasGeometry($workDir, $zoneSlug)) {
            $this->dropStaging($staging);

            return false;
        }

        // The gate runs before promotion here too. The flux names its places far more
        // reliably than OSM does, so this mostly refuses nothing — but the decision must
        // live in one place for both sources, which is the whole point of #884.
        $gate = $this->placeEnrichmentPass->run($workDir, $staging, 'accommodations');
        $this->promote($zoneSlug, $staging, $gate);
        $this->dropStaging($staging);

        return true;
    }

    /**
     * Whether the zone has a geometry in the registry to clip the promotion against.
     *
     * @throws ImportFailedException
     */
    private function zoneHasGeometry(string $workDir, string $zoneSlug): bool
    {
        $path = $workDir.'/zone-geometry.tsv';
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
     * Stages and promotes in one call, for a run with no OSM step to interleave.
     *
     * @throws ImportFailedException
     */
    public function run(string $workDir, string $zoneSlug): void
    {
        $this->stage($workDir, $zoneSlug);
        $this->finish($workDir, $zoneSlug);
    }

    /**
     * Accommodation objects dropped during the last {@see run} because their
     * DataTourisme subtype maps to no app category. Reported by the provisioning
     * command: a new ontology type shows up as a number instead of being folded
     * into an unreachable catch-all bucket (issue #865).
     */
    public function unmappedAccommodationCount(): int
    {
        return $this->mapper->unmappedAccommodationCount();
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
     * Streams the flux ZIP and writes one text-format COPY file per table. The
     * Wikidata enrichment collects its Q-IDs straight from the loaded staging
     * tables (see {@see WikidataEnrichmentPass}), so nothing is tracked here.
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
        // The mapper already narrowed the source object down to the keys worth
        // keeping (see DataTourismeMapper::tags); this only serialises them.
        $tags = json_encode($row['tags'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        $values = match ($table) {
            'cultural_pois', 'food_pois' => [$row['id'], $row['name'], $row['category'], $row['openingHours'], $row['description'], $row['website'], $row['wikidata'], $tags, $geom],
            'accommodations' => [$row['id'], $row['name'], $row['category'], $row['capacity'], $row['price'], $row['description'], $row['openingHours'], $row['website'], $row['phone'], $row['wikidata'], $tags, $geom],
            'events' => [$row['id'], $row['name'], $row['category'], $row['startDate'], $row['endDate'], $row['website'], $row['description'], $row['price'], $tags, $geom],
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
    private function load(string $stagingSchema, array $copyFiles): void
    {
        $ddl = \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s;', $stagingSchema);
        foreach (self::STAGING_DDL as $table => $columns) {
            $ddl .= \sprintf(' CREATE TABLE %s.%s (%s);', $stagingSchema, $table, $columns);
        }

        $this->runProcess(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $ddl], 'psql create tourism staging');

        foreach ($copyFiles as $table => $path) {
            $columns = implode(', ', self::TABLE_COLUMNS[$table]);
            $copy = \sprintf("\\copy %s.%s (%s) FROM '%s'", $stagingSchema, $table, $columns, $path);
            $this->runProcess(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $copy], \sprintf('psql copy %s', $table));
        }

        foreach (array_keys(self::TABLE_COLUMNS) as $table) {
            // Not a mirror of the live index any more (promotion is an INSERT, so the
            // live indexes stay): this one serves the zone clip, which tests every
            // staged row against the zone polygon with ST_Covers.
            $this->runProcess([
                'psql', '-v', 'ON_ERROR_STOP=1', '-c',
                \sprintf('CREATE INDEX ON %s.%s USING gist (geom);', $stagingSchema, $table),
            ], \sprintf('psql index %s', $table));
        }
    }

    /**
     * Promotes the staged flux rows covered by the zone into the live schema in one
     * transaction, then refreshes the provisioning metadata from the live tables
     * (refresh timestamp, per-table counts, per-table completeness ratios and the
     * discarded-row counts), which is what /api/health reports.
     *
     * @param array{resolved?: int, rejected?: int, matched?: int, ambiguous?: int, reasons?: array<string, int>} $gate what the completeness gate resolved and refused
     *
     * @throws ImportFailedException
     */
    private function promote(string $zoneSlug, string $stagingSchema, array $gate): void
    {
        $counts = implode(', ', array_map(
            static fn (string $table): string => \sprintf("'%1\$s', (SELECT count(*) FROM %2\$s.%1\$s)", $table, self::LIVE_SCHEMA),
            array_keys(self::TABLE_COLUMNS),
        ));
        $completeness = new CompletenessMetrics(self::LIVE_SCHEMA)
            ->expression(self::COMPLETENESS_METRICS, self::COMPLETENESS_BY_CATEGORY);
        // The only rejection measurable before the completeness gate of #884: flux
        // accommodations whose subtype maps to no app category (see the mapper).
        $rejections = \sprintf(
            "jsonb_build_object('accommodation_unmapped_category', %d, 'accommodation_incomplete', %d, 'accommodation_name_resolved', %d)",
            $this->mapper->unmappedAccommodationCount(),
            $gate['rejected'] ?? 0,
            $gate['resolved'] ?? 0,
        );

        $metadataRefresh = \sprintf(
            <<<'SQL'
                DELETE FROM %1$s.metadata;
                INSERT INTO %1$s.metadata (refreshed_at, feature_counts, completeness, rejections)
                SELECT now(), jsonb_build_object(%2$s), %3$s, %4$s;
                SQL,
            self::LIVE_SCHEMA,
            $counts,
            $completeness,
            $rejections,
        );

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c', $this->promotion->reportDdl(),
        ], 'psql prepare promotion report');

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c',
            $this->promotion->sql($zoneSlug, $stagingSchema, clipToZone: $zoneSlug, registryUpsert: $metadataRefresh),
        ], \sprintf('psql promote datatourisme zone %s', $zoneSlug));
    }

    /**
     * @throws ImportFailedException
     */
    private function dropStaging(string $stagingSchema): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %s CASCADE;', $stagingSchema),
        ], 'psql drop tourism staging schema');
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
