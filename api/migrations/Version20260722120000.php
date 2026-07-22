<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `ai_overview_stale` flag to the trip table.
 *
 * Set when trip data changes after an AI overview was generated, so the
 * frontend can surface an "analysis outdated" note + a manual regenerate
 * button instead of silently recomputing on every edit (recette AI lifecycle).
 */
final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_overview_stale flag to trip table (AI overview outdated marker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip ADD ai_overview_stale BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP ai_overview_stale');
    }
}
