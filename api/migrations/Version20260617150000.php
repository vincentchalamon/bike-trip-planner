<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Wikidata enrichment columns to the OSM reference tables that carry a
 * `wikidata` Q-ID (ADR-040/041): osm.cultural_pois and osm.accommodations, filled
 * by the provisioner's shared enrichment pass joining provisioner.wikidata_cache.
 *
 * cultural_pois had only name/category/wikidata, so it gains the full set;
 * accommodations already had website/opening_hours from OSM tags, so it only
 * gains the Wikidata-specific columns. Bootstraps the columns for the first
 * provisioning run and the functional tests; tier1.lua carries the same columns
 * so osm2pgsql creates them in staging and the atomic swap preserves them.
 */
final class Version20260617150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Wikidata enrichment columns to osm.cultural_pois and osm.accommodations';
    }

    public function up(Schema $schema): void
    {
        foreach (['description', 'opening_hours', 'website', 'image_url', 'wikipedia_url'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE osm.cultural_pois ADD COLUMN IF NOT EXISTS %s text', $column));
        }

        foreach (['description', 'image_url', 'wikipedia_url'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE osm.accommodations ADD COLUMN IF NOT EXISTS %s text', $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['description', 'opening_hours', 'website', 'image_url', 'wikipedia_url'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE osm.cultural_pois DROP COLUMN IF EXISTS %s', $column));
        }

        foreach (['description', 'image_url', 'wikipedia_url'] as $column) {
            $this->addSql(\sprintf('ALTER TABLE osm.accommodations DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
