<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_analysis JSONB column to stage table for LLaMA 8B pass-1 analysis (#301)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage ADD ai_analysis JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage DROP ai_analysis');
    }
}
