<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the local-first provisioning monitoring tables (ADR-040):
 *
 * - osm.coverage: a single-row coverage polygon (union of the admin_level=2
 *   country boundaries) the API tests trip geometry against via ST_Covers to
 *   flag out-of-zone trips.
 * - osm.metadata / tourism.metadata: a refresh timestamp + per-table feature
 *   counts, surfaced by /api/health so operators see reference-data freshness.
 *
 * The provisioner builds these in its staging schema and atomically swaps them
 * onto the live schemas (provisioner/src/PostgisImporter::buildDerived and
 * DataTourismeImporter::load). This migration bootstraps them so they exist
 * before the first provisioning run and so functional tests can query them.
 */
final class Version20260616130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the coverage polygon and provisioning metadata tables for local-first monitoring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS osm');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS tourism');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.coverage (
                geom geometry(MultiPolygon, 4326)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS coverage_geom_idx ON osm.coverage USING gist (geom)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS osm.metadata (
                refreshed_at timestamptz,
                feature_counts jsonb
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tourism.metadata (
                refreshed_at timestamptz,
                feature_counts jsonb
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS osm.coverage');
        $this->addSql('DROP TABLE IF EXISTS osm.metadata');
        $this->addSql('DROP TABLE IF EXISTS tourism.metadata');
    }
}
