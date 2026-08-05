<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zone registry and per-zone provenance for the Tier-1 reference index (ADR-049 §1/§2,
 * issue #883).
 *
 * Until now the provisioner owned the live `osm` schema outright: it imported into
 * `osm_staging` and ran `DROP SCHEMA osm CASCADE; ALTER SCHEMA osm_staging RENAME TO
 * osm`, so the tables these migrations bootstrap were thrown away on the first run.
 * Promotion becomes an `INSERT ... SELECT` per zone, which makes the migrated tables
 * the authoritative ones — their DDL must therefore stay in step with
 * provisioner/osm2pgsql/tier1.lua, and a staging column with no live counterpart now
 * aborts the promotion instead of being silently swapped in.
 *
 * Three additions:
 *
 * 1. `osm.zones` — the source of truth for what has been opened, replacing the
 *    cumulative `regions.json` / `RegionSelectionStore`. Its `geom` is the union of
 *    the administrative boundaries the zone's own extract actually yielded (clipped
 *    extracts drop the coarse levels, see #880), and the union of those geometries is
 *    what `osm.coverage` now materialises: "out of zone" finally means "zone not yet
 *    opened".
 * 2. `osm.routing_perimeter` — the national slugs present in the Valhalla routing
 *    volume, recorded by the provisioner at each run. ADR-049 §6 asserts containment
 *    between two explicit lists rather than between two inferred geometries; this is
 *    the routing side of it, so /api/health can report it without reaching outside
 *    the database.
 * 3. `zone` + `last_seen_at` on every live feature table. `zone` is provenance;
 *    `last_seen_at` is refreshed on re-opening for rows the source still carries and
 *    is **metadata only** — the payload of an already-imported row is never rewritten
 *    (ADR-049 §4/§5). Both are nullable: rows imported by the previous swap-based
 *    pipeline predate any zone and are kept as-is.
 */
final class Version20260805130000 extends AbstractMigration
{
    /**
     * Live OSM feature tables, i.e. the `define_table` calls of
     * provisioner/osm2pgsql/tier1.lua. Kept as a literal: a migration is a
     * historical record and must not change meaning when the style does.
     *
     * @var list<string>
     */
    private const array OSM_TABLES = [
        'pois', 'accommodations', 'water_points', 'bike_shops', 'health_services',
        'railway_stations', 'charging_stations', 'cultural_pois', 'ways',
        'admin_boundaries', 'cycle_routes', 'ferries', 'fords',
    ];

    /**
     * @var list<string>
     */
    private const array TOURISM_TABLES = ['cultural_pois', 'food_pois', 'accommodations', 'events'];

    /**
     * Provenance columns added to every live feature table. Named `COLUMNS` by contract
     * with `DataTourismeImporterTest::theStagingDdlMatchesTheAccommodationMigrations()`,
     * which reads it back to check the staging DDL against the live schema.
     *
     * @var list<string>
     */
    private const array COLUMNS = ['zone', 'last_seen_at'];

    public function getDescription(): string
    {
        return 'Add the zone registry, the routing perimeter and per-zone provenance columns to the reference schemas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS tourism');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.zones (
                slug text NOT NULL,
                name text NOT NULL,
                country text NOT NULL,
                opened_at timestamptz NOT NULL,
                refreshed_at timestamptz NOT NULL,
                pipeline_version integer NOT NULL,
                feature_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                new_entries jsonb NOT NULL DEFAULT '{}'::jsonb,
                geom geometry(MultiPolygon, 4326),
                PRIMARY KEY (slug)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS zones_geom_idx ON osm.zones USING gist (geom)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.routing_perimeter (
                slug text NOT NULL,
                observed_at timestamptz NOT NULL,
                PRIMARY KEY (slug)
            )
            SQL);

        foreach (['osm' => self::OSM_TABLES, 'tourism' => self::TOURISM_TABLES] as $schemaName => $tables) {
            foreach ($tables as $table) {
                foreach (self::COLUMNS as $column) {
                    $this->addSql(\sprintf(
                        'ALTER TABLE %s.%s ADD COLUMN IF NOT EXISTS %s %s',
                        $schemaName,
                        $table,
                        $column,
                        'zone' === $column ? 'text' : 'timestamptz',
                    ));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['tourism' => self::TOURISM_TABLES, 'osm' => self::OSM_TABLES] as $schemaName => $tables) {
            foreach ($tables as $table) {
                foreach (self::COLUMNS as $column) {
                    $this->addSql(\sprintf('ALTER TABLE %s.%s DROP COLUMN IF EXISTS %s', $schemaName, $table, $column));
                }
            }
        }

        $this->addSql('DROP TABLE IF EXISTS osm.routing_perimeter');
        $this->addSql('DROP TABLE IF EXISTS osm.zones');
    }
}
