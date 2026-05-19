<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persists trip chat history long-term in PostgreSQL (#458, sprint 32).
 *
 * Redis still holds the rolling LLM context window; this table stores every
 * turn so the rider can recover their conversation when reloading the page
 * after several hours/days of in-ride consultation.
 */
final class Version20260519163100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trip_chat_message table to persist long-term chat history (#458)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trip_chat_message (
                id UUID NOT NULL,
                trip_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL,
                content TEXT NOT NULL,
                action VARCHAR(32) DEFAULT NULL,
                geo_lat DOUBLE PRECISION DEFAULT NULL,
                geo_lon DOUBLE PRECISION DEFAULT NULL,
                geo_accuracy_m DOUBLE PRECISION DEFAULT NULL,
                pois JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_trip_chat_trip_user_created ON trip_chat_message (trip_id, user_id, created_at)');
        $this->addSql('CREATE INDEX IDX_trip_chat_message_trip ON trip_chat_message (trip_id)');
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trip_chat_message');
    }
}
