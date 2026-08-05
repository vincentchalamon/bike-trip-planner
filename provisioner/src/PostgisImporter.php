<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Imports the Tier-1 reference features (POI, accommodations, water points) from
 * the merged PBF into PostGIS, behind an atomic schema swap (ADR-040).
 *
 * Flow: tags-filter the merged PBF down to the relevant features, import them
 * into a fresh staging schema via osm2pgsql (flex output, osm2pgsql/tier1.lua),
 * then rename the staging schema onto the live schema in one transaction. The
 * live schema keeps serving reads until the swap, so a failed import leaves the
 * previous dataset intact.
 *
 * Database connection is taken from the standard libpq environment
 * (PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE), inherited by osm2pgsql and psql.
 */
final readonly class PostgisImporter
{
    /**
     * Staging schema the flex tables are written into before the atomic swap.
     *
     * This MUST stay equal to the `SCHEMA` constant in osm2pgsql/tier1.lua:
     * osm2pgsql creates the output tables in the schema the Lua style declares,
     * while the DROP/CREATE and the swap here target this name. If the two ever
     * diverge, osm2pgsql writes to one schema and the swap renames the other
     * (empty) schema onto the live one — destroying the live data. Hence a fixed
     * constant rather than a constructor parameter.
     */
    private const string STAGING_SCHEMA = 'osm_staging';

    /**
     * Tag expressions for `osmium tags-filter`; together they keep every feature
     * osm2pgsql/tier1.lua maps (its `is_relevant`). This list MUST stay in sync
     * with the flex style: a category mapped there but missing here is silently
     * dropped from the PBF before import, leaving its table empty. osmium keeps
     * referenced nodes/members by default, so way geometries stay complete.
     *
     * @var list<string>
     */
    private const array TAGS_FILTER_EXPRESSIONS = [
        'nwr/amenity=restaurant,cafe,bar,pub,fast_food,marketplace,pharmacy,hospital,clinic,fuel,drinking_water,water_point,fountain,shelter,bicycle_repair_station,charging_station',
        'nwr/shop=supermarket,convenience,bakery,butcher,greengrocer,deli,general,pastry,farm,bicycle',
        'nwr/tourism=hotel,hostel,guest_house,chalet,camp_site,alpine_hut,wilderness_hut,viewpoint,attraction,museum',
        'nwr/historic=castle,monument,memorial,ruins,archaeological_site,church,cathedral,abbey,fort',
        'nwr/railway=station',
        'nwr/service:bicycle:repair=yes',
        'nwr/man_made=water_tap',
        'nwr/natural=spring',
        'w/highway=primary,secondary,tertiary,unclassified,residential,living_street,service,track,path,cycleway,footway,bridleway',
        // Country (2), region (4), department (6) and commune (8) boundaries: the
        // commune polygons back the offline locality labels (#880), the coarser
        // levels the country resolution, and their union the coverage polygon.
        // Measured on the merged nord-pas-de-calais + rhone-alpes extract
        // (767 MB): widening `r/admin_level=2` to these four levels grows the
        // filtered PBF from 184.7 MB to 193.1 MB (+4.5%) and the osm2pgsql import
        // from 227s to 231s. See docs/audit/880-libelles-de-localite-hors-ligne.md.
        'r/admin_level=2,4,6,8',
        'r/route=bicycle',
        'w/route=ferry',
        'r/route=ferry',
        'nw/ford',
    ];

    /**
     * Feature tables osm2pgsql/tier1.lua writes; their row counts go into the
     * provisioning metadata surfaced by /api/health. Must stay in sync with the
     * flex style's `define_table` calls.
     *
     * @var list<string>
     */
    private const array FEATURE_TABLES = [
        'pois', 'accommodations', 'water_points', 'bike_shops', 'health_services',
        'railway_stations', 'charging_stations', 'cultural_pois', 'ways', 'admin_boundaries', 'cycle_routes', 'ferries', 'fords',
    ];

    /**
     * OSM tables carrying a `wikidata` Q-ID column, enriched from Wikidata via the
     * shared {@see WikidataEnrichmentPass} after import and before the swap.
     *
     * @var list<string>
     */
    private const array WIKIDATA_TABLES = ['cultural_pois', 'accommodations'];

    /**
     * Completeness metrics recorded per table (issue #877): metric key => the text
     * column whose presence is measured. `website` is the exploitable link — what
     * the user opens to verify a place themselves — so a table without one simply
     * has no `with_link` metric.
     *
     * `ways` is absent on purpose: it carries only `tags` + `geom`, so there is
     * nothing to measure, and it is the largest table by an order of magnitude.
     *
     * @var array<string, array<string, string>>
     */
    private const array COMPLETENESS_METRICS = [
        'pois' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours'],
        'accommodations' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours'],
        'cultural_pois' => ['named' => 'name', 'with_link' => 'website', 'with_hours' => 'opening_hours'],
        'water_points' => ['named' => 'name'],
        'bike_shops' => ['named' => 'name'],
        'health_services' => ['named' => 'name'],
        'railway_stations' => ['named' => 'name'],
        'charging_stations' => ['named' => 'name'],
        'admin_boundaries' => ['named' => 'name'],
        'cycle_routes' => ['named' => 'name'],
        'ferries' => ['named' => 'name'],
        'fords' => ['named' => 'name'],
    ];

    /**
     * Tables also broken down per `category`. Accommodations only: the per-category
     * share of unnamed rows is what arbitrates excluding them (issue #878), and
     * each breakdown costs one extra scan.
     *
     * @var list<string>
     */
    private const array COMPLETENESS_BY_CATEGORY = ['accommodations'];

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    private WikidataEnrichmentPass $enrichmentPass;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the osmium/osm2pgsql/psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        private string $flexStylePath,
        private string $liveSchema = 'osm',
        private int $cacheMb = 800,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 1800.0,
        WikidataEnricher $enricher = new WikidataEnricher(),
        string $locale = 'fr',
        int $cacheTtlDays = 30,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
        $this->enrichmentPass = new WikidataEnrichmentPass($this->processFactory, $enricher, $locale, $cacheTtlDays, $this->timeoutSeconds);
    }

    /**
     * @throws ImportFailedException
     */
    public function run(string $mergedPbf, string $filteredPbf): void
    {
        $this->filter($mergedPbf, $filteredPbf);
        $this->import($filteredPbf);
        // Enrich the Wikidata-bearing tables before deriving coverage/metadata and
        // swapping, so the enrichment ships with the dataset that goes live. The
        // COPY scratch files go next to the filtered PBF (the /data work dir).
        $this->enrichmentPass->run(\dirname($filteredPbf), self::STAGING_SCHEMA, self::WIKIDATA_TABLES);
        $this->buildDerived();
        $this->swap();
    }

    /**
     * @throws ImportFailedException
     */
    public function filter(string $mergedPbf, string $filteredPbf): void
    {
        $this->runProcess(
            array_merge(['osmium', 'tags-filter', '--overwrite', '-o', $filteredPbf, $mergedPbf], self::TAGS_FILTER_EXPRESSIONS),
            'osmium tags-filter',
        );
    }

    /**
     * @throws ImportFailedException
     */
    public function import(string $filteredPbf): void
    {
        // Fresh staging schema (drop any half-written leftover from a prior crash).
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s;', self::STAGING_SCHEMA),
        ], 'psql create staging schema');

        $this->runProcess([
            'osm2pgsql',
            '--create',
            '--slim',
            '--drop',
            '--output=flex',
            '--style', $this->flexStylePath,
            '--cache', (string) $this->cacheMb,
            '--middle-schema', self::STAGING_SCHEMA,
            $filteredPbf,
        ], 'osm2pgsql import');
    }

    /**
     * Builds the derived tables in the staging schema (so the atomic swap ships
     * them with the data): the coverage polygon (union of the imported
     * administrative boundaries, tested by the API via ST_Covers to flag
     * out-of-zone trips) and the provisioning metadata (refresh timestamp,
     * per-table feature counts and per-table completeness ratios, surfaced by
     * /api/health).
     *
     * The union spans every admin level rather than admin_level=2 alone (#880).
     * Geofabrik regional extracts are clipped, so the country relation is
     * incomplete and osm2pgsql skips it: on the local nord-pas-de-calais +
     * rhone-alpes set the level-2 union produced a single NULL row, i.e. no
     * coverage at all, which silently disabled the out-of-zone check. Unioning
     * every level uses whatever did build (departments and communes here) and
     * heals the holes left by a boundary that did not, since the levels nest.
     *
     * @throws ImportFailedException
     */
    public function buildDerived(): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                'CREATE TABLE %1$s.coverage AS SELECT ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) AS geom FROM %1$s.admin_boundaries; CREATE INDEX ON %1$s.coverage USING gist (geom);',
                self::STAGING_SCHEMA,
            ),
        ], 'psql build coverage');

        $counts = implode(', ', array_map(
            static fn (string $table): string => \sprintf("'%1\$s', (SELECT count(*) FROM %2\$s.%1\$s)", $table, self::STAGING_SCHEMA),
            self::FEATURE_TABLES,
        ));
        $completeness = new CompletenessMetrics(self::STAGING_SCHEMA)
            ->expression(self::COMPLETENESS_METRICS, self::COMPLETENESS_BY_CATEGORY);

        // `rejections` stays empty here: nothing measurable is discarded on this
        // side today (osmium filters the PBF before osm2pgsql ever sees it). The
        // column exists so the quality gate can fill it with its accepted /
        // discarded counts and motives without a schema change, and so
        // /api/health reports the same shape for both sources.
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                'CREATE TABLE %1$s.metadata AS SELECT now() AS refreshed_at, jsonb_build_object(%2$s) AS feature_counts, %3$s AS completeness, \'{}\'::jsonb AS rejections;',
                self::STAGING_SCHEMA,
                $counts,
                $completeness,
            ),
        ], 'psql build metadata');
    }

    /**
     * @throws ImportFailedException
     */
    public function swap(): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %s CASCADE; ALTER SCHEMA %s RENAME TO %s;', $this->liveSchema, self::STAGING_SCHEMA, $this->liveSchema),
        ], 'psql schema swap');
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
