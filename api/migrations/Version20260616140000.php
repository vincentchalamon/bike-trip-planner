<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds tourism.food_pois (ADR-040): the DataTourisme food layer — eateries
 * (FoodEstablishment: restaurants, bars, cafes, fast food, bakeries) and food
 * shops (Store subtypes Bakery / LocalProductsShop) — imported by the
 * provisioner alongside the existing cultural_pois. It feeds the resupply
 * consumer (ScanPoisHandler / supply timeline), merged with the OSM pois by
 * proximity + name in a follow-up read-side slice.
 *
 * Same shape as tourism.cultural_pois; the DataTourisme `@id` URI is the primary
 * key so daily refreshes upsert in place. Bootstraps the table for the first
 * provisioning run and the functional tests.
 */
final class Version20260616140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tourism.food_pois (DataTourisme eateries + food shops) for local-first resupply reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS tourism');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tourism.food_pois (
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
        $this->addSql('CREATE INDEX IF NOT EXISTS food_pois_geom_idx ON tourism.food_pois USING gist (geom)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tourism.food_pois');
    }
}
