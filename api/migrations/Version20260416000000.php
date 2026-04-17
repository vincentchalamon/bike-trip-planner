<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add access_request table for early-access workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE access_request (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                status VARCHAR(32) NOT NULL,
                verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_access_request_email ON access_request (email)');
        $this->addSql('CREATE INDEX idx_access_request_status ON access_request (status)');
        $this->addSql("COMMENT ON COLUMN access_request.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN access_request.verified_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE access_request');
    }
}
