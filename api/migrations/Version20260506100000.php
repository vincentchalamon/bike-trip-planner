<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_overview JSONB column to trip table for LLaMA 8B pass-2 trip overview (#302)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip ADD ai_overview JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP ai_overview');
    }
}
