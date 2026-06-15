<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.bike_shops to the local-first Tier-1 reference schema (ADR-040): a
 * PostGIS point table read by the API via ST_DWithin corridor queries, replacing
 * the runtime Overpass bike-shop scan. Raw tags are kept so the read side can
 * distinguish repair shops (service:bicycle:repair=yes) from sale-only outlets.
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and so functional tests have it to seed and query.
 */
final class Version20260615120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.bike_shops reference table for local-first bike-shop reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.bike_shops (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                category text NOT NULL,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS bike_shops_geom_idx ON osm.bike_shops USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.bike_shops');
    }
}
