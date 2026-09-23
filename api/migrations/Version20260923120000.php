<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remembers what a client already created, so a retry does not create it twice (ADR-077).
 *
 * The unique index is the guarantee, not the lookup that precedes it: two concurrent calls
 * carrying the same key both insert, one loses, and the loser reads the winner's row instead of
 * making a second trip. A pre-check alone leaves the window open between reading and writing.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record idempotency keys for the operations that create a trip (ADR-077)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_key (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                operation VARCHAR(128) NOT NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                request_digest VARCHAR(32) NOT NULL,
                resource_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_idempotency_key ON idempotency_key (user_id, operation, idempotency_key)');
        $this->addSql('CREATE INDEX idx_idempotency_key_created_at ON idempotency_key (created_at)');
        $this->addSql('ALTER TABLE idempotency_key ADD CONSTRAINT fk_idempotency_key_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_key');
    }
}
