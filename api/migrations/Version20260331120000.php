<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trip_share table for read-only trip sharing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trip_share (id UUID NOT NULL, trip_id UUID NOT NULL, token VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_trip_share_token ON trip_share (token)');
        $this->addSql('CREATE INDEX idx_trip_share_trip ON trip_share (trip_id)');
        $this->addSql('COMMENT ON COLUMN trip_share.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN trip_share.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE trip_share ADD CONSTRAINT fk_trip_share_trip FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip_share DROP CONSTRAINT fk_trip_share_trip');
        $this->addSql('DROP TABLE trip_share');
    }
}
