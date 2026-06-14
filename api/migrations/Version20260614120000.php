<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the local-first Tier-1 reference schema `osm` (ADR-040): pois,
 * accommodations and water_points as PostGIS point tables, read by the API via
 * ST_DWithin corridor queries instead of the runtime Overpass API.
 *
 * The structure mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua):
 * the provisioner imports into a staging schema and atomically swaps it onto `osm`,
 * replacing what this migration bootstraps. This migration exists so the schema is
 * present before the first provisioning run (the API returns empty results rather
 * than erroring) and so functional tests have the tables to seed and query. The
 * tables live in their own schema, outside Doctrine's managed (public) metadata.
 */
final class Version20260614120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the osm reference schema (pois, accommodations, water_points) for local-first reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.pois (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                category text NOT NULL,
                opening_hours text,
                website text,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.accommodations (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                category text NOT NULL,
                stars integer,
                capacity integer,
                fee text,
                website text,
                wikidata text,
                opening_hours text,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.water_points (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                category text NOT NULL,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS pois_geom_idx ON osm.pois USING gist (geom)');
        $this->addSql('CREATE INDEX IF NOT EXISTS pois_category_idx ON osm.pois USING btree (category)');
        $this->addSql('CREATE INDEX IF NOT EXISTS accommodations_geom_idx ON osm.accommodations USING gist (geom)');
        $this->addSql('CREATE INDEX IF NOT EXISTS accommodations_category_idx ON osm.accommodations USING btree (category)');
        $this->addSql('CREATE INDEX IF NOT EXISTS water_points_geom_idx ON osm.water_points USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS osm CASCADE');
    }
}
