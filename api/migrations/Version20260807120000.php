<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the orphaned `trip_chat_message` table (#937, sprint 51).
 *
 * The entity, repository, API resource and read path were removed in #929; the
 * table was kept behind per ADR-032's two-step destructive-migration pattern
 * (one release stops writing, the next drops). No `v*` tag has ever shipped, so
 * no release exists to roll back to and the production database has never been
 * created: the grace window has no data to protect. Under the pre-launch
 * exception recorded in ADR-032, the drop happens in the same pre-launch window
 * that stopped writing (#929). The two-release rule resumes at the first tag.
 *
 * The columns/indexes recreated by down() come from Version20260519163100
 * (base table, #458) and Version20260520120000 (in-ride payload columns, #465).
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the orphaned trip_chat_message table (#937)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE trip_chat_message');
    }

    /**
     * Recreates the table structure only. The chat history it once held is NOT
     * recoverable — down() restores the empty schema so a rollback does not
     * fail, never the data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trip_chat_message (
                id UUID NOT NULL,
                trip_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL,
                content TEXT NOT NULL,
                action VARCHAR(32) DEFAULT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                geo_lat DOUBLE PRECISION DEFAULT NULL,
                geo_lon DOUBLE PRECISION DEFAULT NULL,
                pois JSONB DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_trip_chat_trip_user_created ON trip_chat_message (trip_id, user_id, created_at)');
        $this->addSql('CREATE INDEX IDX_trip_chat_message_user ON trip_chat_message (user_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE trip_chat_message
                ADD CONSTRAINT FK_trip_chat_message_trip FOREIGN KEY (trip_id)
                REFERENCES trip (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE trip_chat_message
                ADD CONSTRAINT FK_trip_chat_message_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }
}
