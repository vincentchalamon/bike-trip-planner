<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.cycle_routes to the local-first Tier-1 reference schema (ADR-040):
 * signed cycle route relations (type=route, route=bicycle — EuroVelo, national
 * (ncn) / regional (rcn) / local (lcn) networks, voies vertes) stored as PostGIS
 * MultiLineStrings. The API measures how much of each stage follows one (the
 * "on cycle network" indicator).
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and for the functional tests.
 */
final class Version20260616150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.cycle_routes reference table (signed cycle networks) for the on-cycle-network indicator';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.cycle_routes (
                osm_id bigint NOT NULL,
                name text,
                network text,
                ref text,
                tags jsonb,
                geom geometry(MultiLineString, 4326) NOT NULL,
                PRIMARY KEY (osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS cycle_routes_geom_idx ON osm.cycle_routes USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.cycle_routes');
    }
}
