<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add market table for weekly market import from data.gouv.fr';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE market (
                id UUID NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                day_of_week INT NOT NULL,
                start_time VARCHAR(5) DEFAULT NULL,
                end_time VARCHAR(5) DEFAULT NULL,
                commune VARCHAR(255) NOT NULL,
                department VARCHAR(255) NOT NULL,
                source VARCHAR(50) NOT NULL,
                imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_market_external_id ON market (external_id)');
        $this->addSql('CREATE INDEX idx_market_day_of_week ON market (day_of_week)');
        $this->addSql("COMMENT ON COLUMN market.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN market.imported_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE market');
    }
}
