<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the persistent Wikidata enrichment cache (ADR-041): a stable schema the
 * provisioner reads/writes across runs, so enrichment resumes instead of
 * re-querying every Q-ID from scratch after a crash or on the daily DataTourisme
 * refresh (Wikidata's effective cadence is monthly, TTL-driven).
 *
 * Lives in its own `provisioner` schema — NOT in `tourism`/`osm`, which the
 * provisioner drops and recreates on every atomic swap; the cache must survive
 * those swaps. `payload` holds the enrichment fields
 * (label/description/website/imageUrl/openingHours/wikipediaUrl), or `{}` for a
 * Q-ID Wikidata returned no data for (negative cache, avoids re-querying it).
 */
final class Version20260617140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provisioner.wikidata_cache for resumable, TTL-driven Wikidata enrichment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS provisioner');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS provisioner.wikidata_cache (
                qid text NOT NULL,
                payload jsonb NOT NULL,
                fetched_at timestamptz NOT NULL,
                PRIMARY KEY (qid)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS wikidata_cache_fetched_at_idx ON provisioner.wikidata_cache (fetched_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS provisioner.wikidata_cache');
        $this->addSql('DROP SCHEMA IF EXISTS provisioner');
    }
}
