<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds osm.ways to the local-first Tier-1 reference schema (ADR-040): highway ways
 * stored as PostGIS LineStrings, read by the API via ST_DWithin corridor queries
 * (centroid + ST_Length computed at read time), replacing the runtime Overpass
 * ways scan for terrain (surface + traffic) analysis.
 *
 * Mirrors the osm2pgsql flex output (provisioner/osm2pgsql/tier1.lua): the
 * provisioner imports into a staging schema and atomically swaps it onto `osm`.
 * This migration bootstraps the table so it exists before the first provisioning
 * run and so functional tests have it to seed and query.
 */
final class Version20260615170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the osm.ways reference table (highway linestrings) for local-first terrain reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.ways (
                osm_id bigint NOT NULL,
                tags jsonb,
                geom geometry(LineString, 4326) NOT NULL,
                PRIMARY KEY (osm_id)
            )
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS ways_geom_idx ON osm.ways USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.ways');
    }
}
