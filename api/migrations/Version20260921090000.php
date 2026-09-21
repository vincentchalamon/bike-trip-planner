<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the enrichment status map a home that outlives the cache (ADR-072).
 *
 * The map lived only in Redis under a 30-minute TTL, which is shorter than the life of a
 * trip. Past it the state ceased to exist anywhere and both read paths fell back to "there
 * are stages, so it must be analysed" — reporting success for a trip whose every computation
 * had failed. Redis stays the hot path; this column is what answers once it is gone.
 *
 * Empty rather than backfilled: there is nothing to backfill from, the cache holding the only
 * copy is exactly the problem. Existing trips fill it in as their next computation settles.
 */
final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mirror the enrichment status map onto trip.computation_status (ADR-072)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE trip ADD computation_status JSONB DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP computation_status');
    }
}
