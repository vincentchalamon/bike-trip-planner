<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partitions the stage enrichments by the producer that owns them (ADR-068).
 *
 * `alerts` held a flat list that only the terrain analyzer ever wrote; `alerts_by_group`
 * holds `{group: {computedAt, alerts[]}}`, which is what lets one enrichment re-run replace
 * its own alerts and leave the twelve others standing. `events` and `supply_timeline` were
 * published over SSE and persisted nowhere, so the anonymous share page lost them.
 *
 * The old column is dropped rather than backfilled: no environment is deployed, and the
 * terrain alerts it holds are recomputed by the first structural edit anyway.
 */
final class Version20260920080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace stage.alerts with alerts_by_group, and persist events and supply_timeline (ADR-068)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stage ADD alerts_by_group JSONB DEFAULT '{}' NOT NULL");
        $this->addSql("ALTER TABLE stage ADD events JSONB DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE stage ADD supply_timeline JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE stage DROP alerts');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stage ADD alerts JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE stage DROP alerts_by_group');
        $this->addSql('ALTER TABLE stage DROP events');
        $this->addSql('ALTER TABLE stage DROP supply_timeline');
    }
}
