<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-043: add the persisted structural-readiness status to the trip table.
 *
 * `draft` until the pacing stages are persisted, then `ready`. The status is
 * independent of the asynchronous enrichment completion gate. Existing trips that
 * already have stages are backfilled to `ready` so reload-safe hydration is correct.
 */
final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structural-readiness status column to trip table (ADR-043)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE trip ADD status VARCHAR(20) DEFAULT 'draft' NOT NULL");
        $this->addSql("UPDATE trip SET status = 'ready' WHERE id IN (SELECT DISTINCT trip_id FROM stage)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP status');
    }
}
