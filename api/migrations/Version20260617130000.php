<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Wikidata enrichment columns to tourism.cultural_pois and
 * tourism.food_pois (ADR-040): website, image_url and wikipedia_url, filled by
 * the provisioner's post-load enrichment pass (batch SPARQL keyed on the
 * `wikidata` Q-ID). This moves the former runtime Wikidata enrichment into the
 * provisioner so the API reads enriched reference data from PostGIS only.
 *
 * Bootstraps the columns for the first provisioning run and the functional
 * tests; the provisioner's staging DDL carries the same columns so the atomic
 * schema swap preserves them.
 */
final class Version20260617130000 extends AbstractMigration
{
    private const array TABLES = ['cultural_pois', 'food_pois'];

    public function getDescription(): string
    {
        return 'Add Wikidata enrichment columns (website, image_url, wikipedia_url) to tourism POI tables';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE tourism.%s ADD COLUMN IF NOT EXISTS website text', $table));
            $this->addSql(\sprintf('ALTER TABLE tourism.%s ADD COLUMN IF NOT EXISTS image_url text', $table));
            $this->addSql(\sprintf('ALTER TABLE tourism.%s ADD COLUMN IF NOT EXISTS wikipedia_url text', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE tourism.%s DROP COLUMN IF EXISTS website', $table));
            $this->addSql(\sprintf('ALTER TABLE tourism.%s DROP COLUMN IF EXISTS image_url', $table));
            $this->addSql(\sprintf('ALTER TABLE tourism.%s DROP COLUMN IF EXISTS wikipedia_url', $table));
        }
    }
}
