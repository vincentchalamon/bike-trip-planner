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
        'nwr/tourism=hotel,hostel,guest_house,motel,chalet,camp_site,alpine_hut,wilderness_hut,apartment,viewpoint,attraction,museum',
        'nwr/historic=castle,monument,memorial,ruins,archaeological_site,church,cathedral,abbey,fort',
        'nwr/railway=station',
        'nwr/service:bicycle:repair=yes',
        'nwr/man_made=water_tap',
        'nwr/natural=spring',
        'w/highway=primary,secondary,tertiary,unclassified,residential,living_street,service,track,path,cycleway,footway,bridleway',
        'r/admin_level=2',
        'r/route=bicycle',
        'w/route=ferry',
        'r/route=ferry',
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
        'railway_stations', 'charging_stations', 'cultural_pois', 'ways', 'admin_boundaries', 'cycle_routes', 'ferries',
    ];

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory factory used to build the osmium/osm2pgsql/psql processes; defaults to a real {@see Process}
     */
    public function __construct(
        private string $flexStylePath,
        private string $liveSchema = 'osm',
        private int $cacheMb = 800,
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 1800.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @throws ImportFailedException
     */
    public function run(string $mergedPbf, string $filteredPbf): void
    {
        $this->filter($mergedPbf, $filteredPbf);
        $this->import($filteredPbf);
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
     * them with the data): the coverage polygon (union of the admin_level=2
     * country boundaries, tested by the API via ST_Covers to flag out-of-zone
     * trips) and the provisioning metadata (refresh timestamp + per-table feature
     * counts, surfaced by /api/health).
     *
     * @throws ImportFailedException
     */
    public function buildDerived(): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                'CREATE TABLE %1$s.coverage AS SELECT ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) AS geom FROM %1$s.admin_boundaries WHERE admin_level = 2; CREATE INDEX ON %1$s.coverage USING gist (geom);',
                self::STAGING_SCHEMA,
            ),
        ], 'psql build coverage');

        $counts = implode(', ', array_map(
            static fn (string $table): string => \sprintf("'%1\$s', (SELECT count(*) FROM %2\$s.%1\$s)", $table, self::STAGING_SCHEMA),
            self::FEATURE_TABLES,
        ));
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf(
                'CREATE TABLE %1$s.metadata AS SELECT now() AS refreshed_at, jsonb_build_object(%2$s) AS feature_counts;',
                self::STAGING_SCHEMA,
                $counts,
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
            throw new ImportFailedException(\sprintf('%s failed (exit %s): %s', $label, (string) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
