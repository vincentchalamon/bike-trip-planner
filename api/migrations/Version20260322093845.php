<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260322093845 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trip and stage tables for persistent storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stage (id UUID NOT NULL, position INT NOT NULL, day_number INT NOT NULL, distance DOUBLE PRECISION NOT NULL, elevation DOUBLE PRECISION NOT NULL, elevation_loss DOUBLE PRECISION NOT NULL, start_lat DOUBLE PRECISION NOT NULL, start_lon DOUBLE PRECISION NOT NULL, start_ele DOUBLE PRECISION NOT NULL, end_lat DOUBLE PRECISION NOT NULL, end_lon DOUBLE PRECISION NOT NULL, end_ele DOUBLE PRECISION NOT NULL, geometry JSON NOT NULL, label VARCHAR(255) DEFAULT NULL, is_rest_day BOOLEAN NOT NULL, weather JSON DEFAULT NULL, alerts JSON NOT NULL, pois JSON NOT NULL, accommodations JSON NOT NULL, selected_accommodation JSON DEFAULT NULL, trip_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C27C9369A5BC2E0E ON stage (trip_id)');
        $this->addSql('CREATE INDEX idx_stage_trip_position ON stage (trip_id, position)');
        $this->addSql('CREATE TABLE trip (id UUID NOT NULL, source_url VARCHAR(2048) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, fatigue_factor DOUBLE PRECISION NOT NULL, elevation_penalty DOUBLE PRECISION NOT NULL, ebike_mode BOOLEAN NOT NULL, departure_hour INT NOT NULL, max_distance_per_day DOUBLE PRECISION NOT NULL, average_speed DOUBLE PRECISION NOT NULL, enabled_accommodation_types JSON NOT NULL, source_type VARCHAR(50) DEFAULT NULL, locale VARCHAR(5) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE stage ADD CONSTRAINT FK_C27C9369A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage DROP CONSTRAINT FK_C27C9369A5BC2E0E');
        $this->addSql('DROP TABLE stage');
        $this->addSql('DROP TABLE trip');
    }
}
