<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user, magic_link, refresh_token tables and add user_id to trip for authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, locale VARCHAR(5) NOT NULL DEFAULT \'fr\', PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE magic_link (id UUID NOT NULL, token VARCHAR(128) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_magic_link_token ON magic_link (token)');
        $this->addSql('CREATE INDEX idx_magic_link_user_expires ON magic_link (user_id, expires_at)');
        $this->addSql('COMMENT ON COLUMN magic_link.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN magic_link.consumed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN magic_link.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE magic_link ADD CONSTRAINT FK_MAGIC_LINK_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE refresh_token (id UUID NOT NULL, token VARCHAR(128) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_token_token ON refresh_token (token)');
        $this->addSql('CREATE INDEX idx_refresh_token_user ON refresh_token (user_id)');
        $this->addSql('COMMENT ON COLUMN refresh_token.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN refresh_token.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_REFRESH_TOKEN_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE trip ADD user_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_trip_user ON trip (user_id)');
        $this->addSql('ALTER TABLE trip ADD CONSTRAINT fk_trip_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP CONSTRAINT fk_trip_user');
        $this->addSql('DROP INDEX idx_trip_user');
        $this->addSql('ALTER TABLE trip DROP COLUMN user_id');
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_REFRESH_TOKEN_USER');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('ALTER TABLE magic_link DROP CONSTRAINT FK_MAGIC_LINK_USER');
        $this->addSql('DROP TABLE magic_link');
        $this->addSql('DROP TABLE "user"');
    }
}
