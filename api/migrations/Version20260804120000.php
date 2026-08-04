<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the completeness and rejection columns to the provisioning metadata tables
 * bootstrapped by Version20260616130000 (issue #877).
 *
 * The importers only recorded row counts, so no data-quality decision was
 * verifiable over time: `completeness` holds the per-table share of rows with a
 * name / a link / opening hours (plus the per-category breakdown for
 * accommodations), `rejections` the discarded-row counts and their motive.
 *
 * As with the row counts, the provisioner builds these in its staging schema and
 * swaps them onto the live schema, so this only bootstraps the columns for
 * instances (and functional tests) that query the metadata before the first
 * provisioning run.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add completeness and rejection columns to the provisioning metadata tables';
    }

    public function up(Schema $schema): void
    {
        foreach (['osm', 'tourism'] as $source) {
            $this->addSql(\sprintf('ALTER TABLE %s.metadata ADD COLUMN IF NOT EXISTS completeness jsonb', $source));
            $this->addSql(\sprintf('ALTER TABLE %s.metadata ADD COLUMN IF NOT EXISTS rejections jsonb', $source));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['osm', 'tourism'] as $source) {
            $this->addSql(\sprintf('ALTER TABLE %s.metadata DROP COLUMN IF EXISTS completeness', $source));
            $this->addSql(\sprintf('ALTER TABLE %s.metadata DROP COLUMN IF EXISTS rejections', $source));
        }
    }
}
