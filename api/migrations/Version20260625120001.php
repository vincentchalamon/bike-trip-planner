<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #777: add the email_change_token table backing the email-change-by-magic-link
 * flow. Mirrors the magic_link schema (high-entropy token, expiry, single-use
 * consumed_at) and carries the pending new address.
 */
final class Version20260625120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_change_token table for email-change-by-magic-link (#777)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_change_token (id UUID NOT NULL, token VARCHAR(128) NOT NULL, new_email VARCHAR(180) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_change_token_token ON email_change_token (token)');
        $this->addSql('CREATE INDEX idx_email_change_token_user_expires ON email_change_token (user_id, expires_at)');
        $this->addSql('COMMENT ON COLUMN email_change_token.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_change_token.consumed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_change_token.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE email_change_token ADD CONSTRAINT FK_EMAIL_CHANGE_TOKEN_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_change_token DROP CONSTRAINT FK_EMAIL_CHANGE_TOKEN_USER');
        $this->addSql('DROP TABLE email_change_token');
    }
}
