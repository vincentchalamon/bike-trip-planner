<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.charging_stations to the local-first Tier-1 reference schema (ADR-040):
 * a PostGIS point table (e-bike charging stations) read by the API via ST_DWithin
 * queries, so the e-bike-range alert can point to the nearest charger without a
 * runtime Overpass scan.
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and so functional tests have it to seed and query.
 */
final class Version20260615150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.charging_stations reference table for local-first e-bike charging reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.charging_stations (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                category text NOT NULL,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS charging_stations_geom_idx ON osm.charging_stations USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.charging_stations');
    }
}
