<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.admin_boundaries to the local-first Tier-1 reference schema (ADR-040):
 * country-level (admin_level=2) administrative boundaries stored as PostGIS
 * MultiPolygons. The API resolves the country at a point via ST_Covers, replacing
 * the runtime Overpass `is_in` query used for border-crossing detection; their
 * union also forms the coverage polygon.
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and so functional tests have it to seed and query.
 */
final class Version20260615180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.admin_boundaries reference table (country multipolygons) for local-first border-crossing reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.admin_boundaries (
                osm_id bigint NOT NULL,
                name text,
                admin_level int,
                tags jsonb,
                geom geometry(MultiPolygon, 4326) NOT NULL,
                PRIMARY KEY (osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS admin_boundaries_geom_idx ON osm.admin_boundaries USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.admin_boundaries');
    }
}
