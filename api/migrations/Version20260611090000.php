<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enables the PostGIS extension for the local-first Tier-1 reference index (ADR-040).
 *
 * The reference dataset (POI, accommodations, water points, admin boundaries,
 * cycle routes) is imported into PostgreSQL/PostGIS by the provisioner and read
 * by the API via spatial corridor queries (ST_DWithin), replacing the runtime
 * Overpass dependency. This migration only enables the extension; the spatial
 * tables themselves are created by the osm2pgsql flex import in a dedicated
 * schema, outside Doctrine's managed metadata.
 */
final class Version20260611090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the PostGIS extension for the local-first spatial reference index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(Schema $schema): void
    {
        // The postgis Docker image enables postgis_topology on a fresh volume; it
        // depends on postgis, so drop it first to avoid a dependency error.
        $this->addSql('DROP EXTENSION IF EXISTS postgis_topology');
        $this->addSql('DROP EXTENSION IF EXISTS postgis');
    }
}
