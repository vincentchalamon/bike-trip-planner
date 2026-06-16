<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `tourism` reference schema (ADR-040): DataTourisme places imported by
 * the provisioner into PostGIS, read by the API instead of the runtime
 * DataTourisme REST API. DataTourisme is FR-only and lives in its OWN schema
 * (not `osm`) so the OSM osm2pgsql swap — which does DROP SCHEMA osm CASCADE —
 * never destroys it; the DataTourisme importer runs its own atomic swap.
 *
 * Three heads matching the existing consumers: cultural POIs
 * (CulturalPoiSourceRegistry), accommodations (AccommodationSourceRegistry) and
 * dated events (ScanEventsHandler). The primary key is the DataTourisme `@id`
 * URI so daily refreshes upsert in place. This migration bootstraps the tables
 * so they exist before the first provisioning run and for the functional tests.
 */
final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the tourism schema (cultural_pois, accommodations, events) for local-first DataTourisme reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS tourism');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tourism.cultural_pois (
                id text NOT NULL,
                name text,
                category text NOT NULL,
                opening_hours text,
                description text,
                wikidata text,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS cultural_pois_geom_idx ON tourism.cultural_pois USING gist (geom)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tourism.accommodations (
                id text NOT NULL,
                name text,
                category text NOT NULL,
                capacity int,
                price numeric(10, 2),
                description text,
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS accommodations_geom_idx ON tourism.accommodations USING gist (geom)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tourism.events (
                id text NOT NULL,
                name text,
                category text NOT NULL,
                start_date date,
                end_date date,
                url text,
                description text,
                price_min numeric(10, 2),
                tags jsonb,
                geom geometry(Point, 4326) NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS events_geom_idx ON tourism.events USING gist (geom)');
        $this->addSql('CREATE INDEX IF NOT EXISTS events_dates_idx ON tourism.events (start_date, end_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS tourism CASCADE');
    }
}
