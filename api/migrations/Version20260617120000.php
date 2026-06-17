<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.fords to the local-first Tier-1 reference schema (ADR-040): fords
 * (ford=yes/stream/... on a node or a highway way) stored as PostGIS points
 * (node position or way centroid). The API flags stages passing close to one
 * (the ford alert, escalated to a warning when rain is forecast).
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and for the functional tests.
 */
final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.fords reference table (ford crossings) for the ford alert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.fords (
                osm_type character(1) NOT NULL,
                osm_id bigint NOT NULL,
                name text,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (osm_type, osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS fords_geom_idx ON osm.fords USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.fords');
    }
}
