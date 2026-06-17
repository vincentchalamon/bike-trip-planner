<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the optional per-user AI provider configuration (ADR-042): the chosen
 * provider and the encrypted API token. ai_token holds ciphertext only (see
 * App\Llm\AiTokenEncryptor); both are cleared on GDPR anonymisation.
 */
final class Version20260617160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_provider and ai_token (encrypted) columns to the user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS ai_provider VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS ai_token TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS ai_provider');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS ai_token');
    }
}
