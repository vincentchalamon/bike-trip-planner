<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings tourism.accommodations up to the shape of its sibling tables (#872).
 *
 * It was the poorest table of the schema: no `website`, no `phone`, no
 * `opening_hours` and no `wikidata`, while tourism.cultural_pois and
 * tourism.food_pois carried all four plus the Wikidata enrichment columns. The
 * consequences chained: no DataTourisme lodging could expose a link even though
 * the flux publishes `foaf:homepage`, the shared Wikidata enrichment pass could
 * not run on the table for lack of a Q-ID column, and NearbyNameDeduplicator was
 * left with the name + 75 m heuristic alone to pair an OSM and a DataTourisme
 * entry.
 *
 * `website` / `phone` / `opening_hours` / `wikidata` are filled by the importer
 * from the flux; `image_url` / `wikipedia_url` are Wikidata-only, filled by the
 * provisioner's enrichment pass, exactly as on the POI tables.
 *
 * Bootstraps the columns for the first provisioning run and the tests; the
 * provisioner's staging DDL declares the same set so the atomic schema swap
 * preserves them.
 */
final class Version20260803120000 extends AbstractMigration
{
    /**
     * Mirrors the `accommodations` entry of DataTourismeImporter::STAGING_DDL;
     * DataTourismeImporterTest asserts the two stay aligned.
     */
    private const array COLUMNS = ['opening_hours', 'website', 'phone', 'image_url', 'wikipedia_url', 'wikidata'];

    public function getDescription(): string
    {
        return 'Add website, phone, opening_hours, wikidata and the Wikidata enrichment columns to tourism.accommodations';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $column) {
            $this->addSql(\sprintf('ALTER TABLE tourism.accommodations ADD COLUMN IF NOT EXISTS %s text', $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $column) {
            $this->addSql(\sprintf('ALTER TABLE tourism.accommodations DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
