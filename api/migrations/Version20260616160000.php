<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.ferries to the local-first Tier-1 reference schema (ADR-040): ferry
 * crossings tagged route=ferry, either on a way (LineString) or on a
 * type=route relation (MultiLineString) — hence a generic geometry column and a
 * composite (osm_type, osm_id) key. The API flags stages whose route runs along
 * one (the ferry-crossing alert).
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and for the functional tests.
 */
final class Version20260616160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.ferries reference table (route=ferry ways + relations) for the ferry-crossing alert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.ferries (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                tags jsonb,
                geom geometry(Geometry, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS ferries_geom_idx ON osm.ferries USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.ferries');
    }
}
