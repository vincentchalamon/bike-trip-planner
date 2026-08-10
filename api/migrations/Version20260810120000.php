<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `source` to tourism.events (issue #975).
 *
 * Events were the last mono-source reference layer: the runtime hard-coded
 * `source: 'datatourisme'` because the column did not exist. The multi-source
 * scan (EventSourceRegistry, OpenAgenda foundation, ADR-051) reads the source
 * from the row instead, so the column has to exist and carry the origin per row.
 *
 * The default 'datatourisme' backfills the rows already imported by the flux and
 * lets the provisioner promote a batch without spelling the source out on every
 * COPY line where it is not written explicitly.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source column to tourism.events (multi-source events, ADR-051)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tourism.events ADD COLUMN source text NOT NULL DEFAULT 'datatourisme'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tourism.events DROP COLUMN source');
    }
}
