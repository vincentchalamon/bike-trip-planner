<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recette #649 (#3c / #9): persist reverse-geocoded stage endpoint labels.
 *
 * Adds `stage.start_label` / `stage.end_label` (city names) so the anonymous
 * shared view — which cannot call the auth-gated /geocode endpoint — and a
 * reloaded trip both render city names instead of raw GPS coordinates.
 * Existing rows keep NULL until the next structural recompute resolves them.
 */
final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist reverse-geocoded stage endpoint labels (start_label / end_label) (recette #649)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage ADD start_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stage ADD end_label VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage DROP start_label');
        $this->addSql('ALTER TABLE stage DROP end_label');
    }
}
