<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Imports the Tier-1 reference features (POI, accommodations, water points) of **one
 * zone** into PostGIS, behind a transactional per-zone promotion (ADR-049 §1/§2).
 *
 * Flow: tags-filter that zone's regional extract down to the relevant features, import
 * them into a staging schema scoped to the zone via osm2pgsql (flex output,
 * osm2pgsql/tier1.lua), enrich the Wikidata-bearing tables, then `INSERT ... SELECT`
 * the keys the live tables do not already hold — registry row, coverage polygon and
 * provisioning metadata included — in a single transaction.
 *
 * What replaced what: promotion used to be `DROP SCHEMA osm CASCADE; ALTER SCHEMA
 * osm_staging RENAME TO osm`, which exposed every zone to every import and, on a
 * staging-schema name out of step with tier1.lua, renamed an empty schema over the live
 * one. That failure mode is gone: the staging schema is now *derived* from the zone and
 * handed to the style through `TIER1_STAGING_SCHEMA`, so the two cannot diverge, and the
 * worst outcome of a promotion bug is 0 rows or a raised exception.
 *
 * Database connection is taken from the standard libpq environment
 * (PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE), inherited by osm2pgsql and psql.
 */
final readonly class PostgisImporter
{
    /**
     * Environment variable through which the staging schema reaches
     * osm2pgsql/tier1.lua (`os.getenv`), so the style writes its output tables exactly
     * where the promotion reads them.
     *
     * This is what makes the schema per-zone without reintroducing the divergence risk
     * the old fixed constant existed to prevent: there is one value, computed once in
     * {@see stagingSchema()}, passed to both sides of the import.
     */
    public const string STAGING_SCHEMA_ENV = 'TIER1_STAGING_SCHEMA';

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
        // levels the country resolution, and their union the zone's registry
        // geometry — hence the coverage polygon.
        // Measured on the merged nord-pas-de-calais + rhone-alpes extract (767 MB):
        // widening `r/admin_level=2` to these four levels grows the filtered PBF
        // from 184.6 MB to 191.6 MB (+3.8%) and the osm2pgsql import from 106s to
        // 160s. See docs/audit/880-libelles-de-localite-hors-ligne.md.
        'r/admin_level=2,4,6,8',
        'r/route=bicycle',
        'w/route=ferry',
        'r/route=ferry',
        'nw/ford',
    ];

    /**
     * Feature tables osm2pgsql/tier1.lua writes, mapped to the predicate matching a live
     * row `l` to a staging row `s`. Must stay in sync with the flex style's
     * `define_table` calls, and with the live DDL the API migrations own — a staging
     * column absent from the live table now aborts the promotion (see
     * {@see ZonePromotion}).
     *
     * `ways`, `admin_boundaries` and `cycle_routes` hold a single object type and
     * therefore have no `osm_type` column, so their identity is the id alone.
     *
     * @var array<string, string>
     */
    private const array FEATURE_TABLES = [
        'pois' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'accommodations' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'water_points' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'bike_shops' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'health_services' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'railway_stations' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'charging_stations' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'cultural_pois' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'ferries' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'fords' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
        'ways' => 'l.osm_id = s.osm_id',
        'admin_boundaries' => 'l.osm_id = s.osm_id',
        'cycle_routes' => 'l.osm_id = s.osm_id',
    ];

    /**
     * Accommodation categories the completeness gate does not apply to, and which are
     * therefore not name-resolved either.
     *
     * `shelter` only, and the measurement in #878 is what arbitrates it: `shelter_type`, not
     * the name, is what separates a mountain refuge from a bus shelter, so a constraint on
     * `name` would drop 429 relevant shelters while keeping 2 516 named ones.
     * `wilderness_hut` gets no exemption (20 unnamed of 316, the level of `guest_house`),
     * nor does `alpine_hut`.
     *
     * @var list<string>
     */
    private const array GATE_EXEMPT_CATEGORIES = ['shelter'];

    /**
     * OSM tables carrying a `wikidata` Q-ID column, enriched from Wikidata via the
     * shared {@see WikidataEnrichmentPass} after import and before promotion.
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

    private ZonePromotion $promotion;

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
        $this->promotion = new ZonePromotion('osm', $this->liveSchema, self::FEATURE_TABLES);
    }

    /**
     * Staging schema for a zone: derived, never configured, so it cannot drift from the
     * value the flex style receives.
     */
    public static function stagingSchema(string $zoneSlug): string
    {
        return 'osm_staging_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($zoneSlug));
    }

    /**
     * Opens (or re-opens) one zone.
     *
     * @param string      $zoneName    display name recorded in the registry
     * @param string      $countrySlug country the zone belongs to, compared against the routing perimeter
     * @param string|null $reportDir   root under which `<zone>/rejected.tsv` is written (#886); null skips it
     *
     * @return array{resolved: int, rejected: int, matched: int, ambiguous: int, reasons: array<string, int>} what the completeness gate resolved and refused
     *
     * @throws ImportFailedException
     */
    public function run(string $zoneSlug, string $zoneName, string $countrySlug, string $regionPbf, string $filteredPbf, ?string $curatedTable = null, ?string $reportDir = null): array
    {
        $staging = self::stagingSchema($zoneSlug);

        $this->filter($regionPbf, $filteredPbf);
        $this->import($staging, $filteredPbf);
        // Enrich the Wikidata-bearing tables before promotion, so the enrichment ships
        // with the rows that go live. The COPY scratch files go next to the filtered PBF
        // (the /data work dir).
        $this->enrichmentPass->run(\dirname($filteredPbf), $staging, self::WIKIDATA_TABLES);
        // Names are resolved and the completeness gate applied *before* promotion, so the
        // gate decides once and the live CHECK only ever fires on a bug here (#884).
        $gate = $this->placeEnrichmentPass($curatedTable)->run(
            \dirname($filteredPbf),
            $staging,
            'accommodations',
            null === $reportDir ? null : \sprintf('%s/%s/rejected.tsv', $reportDir, $zoneSlug),
        );
        $this->promote($zoneSlug, $zoneName, $countrySlug, $staging, $gate);
        $this->dropStaging($staging);

        return $gate;
    }

    /**
     * @throws ImportFailedException
     */
    public function filter(string $regionPbf, string $filteredPbf): void
    {
        $this->runProcess(
            array_merge(['osmium', 'tags-filter', '--overwrite', '-o', $filteredPbf, $regionPbf], self::TAGS_FILTER_EXPRESSIONS),
            'osmium tags-filter',
        );
    }

    /**
     * @throws ImportFailedException
     */
    public function import(string $stagingSchema, string $filteredPbf): void
    {
        // Fresh staging schema (drop any half-written leftover from a prior crash). Only
        // ever this zone's staging schema: no live schema is named here.
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s;', $stagingSchema),
        ], 'psql create staging schema');

        $this->runProcess([
            'osm2pgsql',
            '--create',
            '--slim',
            '--drop',
            '--output=flex',
            '--style', $this->flexStylePath,
            '--cache', (string) $this->cacheMb,
            '--middle-schema', $stagingSchema,
            $filteredPbf,
        ], 'osm2pgsql import', [self::STAGING_SCHEMA_ENV => $stagingSchema]);
    }

    /**
     * The name-resolution + gate pass, built per run because its curated match table depends
     * on the zone being opened.
     *
     * Boundaries come from the staging schema: the zone has just imported its own, and they
     * are the ones covering its places (#880). `$curatedTable` is the DataTourisme staging
     * table when the flux was staged for this run, which is what lets an anonymous OSM
     * accommodation borrow a name from the curated source (#885); null when DataTourisme is
     * not configured, in which case the resolver simply has one fewer step.
     */
    private function placeEnrichmentPass(?string $curatedTable): PlaceEnrichmentPass
    {
        return new PlaceEnrichmentPass(
            source: 'osm',
            identity: "a.osm_type || '/' || a.osm_id",
            exemptCategories: self::GATE_EXEMPT_CATEGORIES,
            liveSchema: $this->liveSchema,
            processFactory: $this->processFactory,
            timeoutSeconds: $this->timeoutSeconds,
            matchTable: $curatedTable,
        );
    }

    /**
     * Promotes the zone's staging tables into the live schema in one transaction, and
     * with them everything derived from the live state: the registry row (its geometry
     * being the union of the boundaries this extract actually yielded), the coverage
     * polygon and the provisioning metadata.
     *
     * `osm.coverage` is now the union of the **opened zones** rather than of whatever
     * boundaries happened to be in the extract, so "out of zone" finally means "zone not
     * yet opened". Geofabrik extracts are clipped, so a zone whose coarse levels did not
     * build contributes the levels that did (#880) — and a zone that yielded no boundary
     * at all keeps whatever geometry a previous run recorded rather than losing it.
     *
     * @param array{resolved?: int, rejected?: int, matched?: int, ambiguous?: int, reasons?: array<string, int>} $gate what the completeness gate resolved and refused, recorded in the metadata
     *
     * @throws ImportFailedException
     */
    public function promote(string $zoneSlug, string $zoneName, string $countrySlug, string $stagingSchema, array $gate = []): void
    {
        $counts = implode(', ', array_map(
            fn (string $table): string => \sprintf("'%1\$s', (SELECT count(*) FROM %2\$s.%1\$s)", $table, $this->liveSchema),
            array_keys(self::FEATURE_TABLES),
        ));
        $completeness = new CompletenessMetrics($this->liveSchema)
            ->expression(self::COMPLETENESS_METRICS, self::COMPLETENESS_BY_CATEGORY);

        // What the completeness gate refused, with its motive, so a gate that starts
        // rejecting everything is visible in /api/health instead of showing up as a
        // quietly thinner index (#884).
        $registryUpsert = \sprintf(
            <<<'SQL'
                INSERT INTO %1$s.zones (slug, name, country, opened_at, refreshed_at, pipeline_version, feature_counts, new_entries, geom)
                SELECT %2$s, %3$s, %4$s, now(), now(), %5$d, candidates, counts,
                       (SELECT ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) FROM %6$s.admin_boundaries)
                ON CONFLICT (slug) DO UPDATE
                   SET name = excluded.name,
                       country = excluded.country,
                       refreshed_at = excluded.refreshed_at,
                       pipeline_version = excluded.pipeline_version,
                       feature_counts = excluded.feature_counts,
                       new_entries = excluded.new_entries,
                       geom = COALESCE(excluded.geom, %1$s.zones.geom);

                DELETE FROM %1$s.coverage;
                INSERT INTO %1$s.coverage (geom)
                SELECT ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) FROM %1$s.zones WHERE geom IS NOT NULL;

                DELETE FROM %1$s.metadata;
                INSERT INTO %1$s.metadata (refreshed_at, feature_counts, completeness, rejections)
                SELECT now(), jsonb_build_object(%7$s), %8$s, %9$s;
                SQL,
            $this->liveSchema,
            ZonePromotion::literal($zoneSlug),
            ZonePromotion::literal($zoneName),
            ZonePromotion::literal($countrySlug),
            ZonePromotion::PIPELINE_VERSION,
            $stagingSchema,
            $counts,
            $completeness,
            $this->rejectionsExpression($gate),
        );

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c', $this->promotion->reportDdl(),
        ], 'psql prepare promotion report');

        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c',
            $this->promotion->sql($zoneSlug, $stagingSchema, registryUpsert: $registryUpsert),
        ], \sprintf('psql promote zone %s', $zoneSlug));
    }

    /**
     * The gate's outcome as a jsonb literal for `osm.metadata.rejections`: how many rows the
     * completeness gate refused and why. Empty when the gate did not run (a direct
     * {@see promote()} call in a test), which reads the same as "nothing was refused".
     *
     * @param array{resolved?: int, rejected?: int, matched?: int, ambiguous?: int, reasons?: array<string, int>} $gate
     */
    private function rejectionsExpression(array $gate): string
    {
        if ([] === $gate) {
            return "'{}'::jsonb";
        }

        $payload = [
            'accommodation_incomplete' => $gate['rejected'] ?? 0,
            'accommodation_name_resolved' => $gate['resolved'] ?? 0,
            // The measurement #885 asks for, emitted by every run rather than produced once:
            // how many anonymous rows the curated source actually named, and how many matches
            // were refused as ambiguous. Those two numbers are what justify or move the radius.
            'accommodation_matched_from_datatourisme' => $gate['matched'] ?? 0,
            'accommodation_ambiguous_matches' => $gate['ambiguous'] ?? 0,
            'accommodation_incomplete_reasons' => $gate['reasons'] ?? [],
        ];

        return \sprintf(
            '%s::jsonb',
            ZonePromotion::literal(json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}'),
        );
    }

    /**
     * @throws ImportFailedException
     */
    public function dropStaging(string $stagingSchema): void
    {
        $this->runProcess([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf('DROP SCHEMA IF EXISTS %s CASCADE;', $stagingSchema),
        ], 'psql drop staging schema');
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @throws ImportFailedException
     */
    private function runProcess(array $command, string $label, array $env = []): void
    {
        $process = ($this->processFactory)($command);
        $process->setTimeout($this->timeoutSeconds);
        if ([] !== $env) {
            $process->setEnv($env);
        }

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
