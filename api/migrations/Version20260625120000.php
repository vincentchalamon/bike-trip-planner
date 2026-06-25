<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #775: persist the expensive PostGIS trip-detail metrics at stage-store time.
 *
 * Adds `stage.on_cycle_network` (fraction 0..1 of the stage following a signed cycle
 * route) and `trip.out_of_zone` (route outside the provisioned coverage area) so the
 * trip-detail read path no longer runs the costly `onNetworkFractions` / `isRouteOutOfZone`
 * PostGIS queries on every reload. Existing rows keep the column defaults (0 / false)
 * until the next structural recompute persists the real values.
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist on_cycle_network (stage) and out_of_zone (trip) for O(1) trip-detail reads (#775)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage ADD on_cycle_network DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trip ADD out_of_zone BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stage DROP on_cycle_network');
        $this->addSql('ALTER TABLE trip DROP out_of_zone');
    }
}
