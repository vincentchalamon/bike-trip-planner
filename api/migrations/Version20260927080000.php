<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The durable half of an authorisation: when it was given, and when it was last used (#1308).
 *
 * The token tables answer neither question. They carry one temporal column each — `expiry`, a
 * future instant — and rotation rewrites them every fifteen minutes, so the moment a user let an
 * application in is nowhere by the time they think to look. This table keeps it, and keeps
 * nothing that the tokens already say: whether the application can still act stays a question
 * for them.
 *
 * Two choices in the DDL are load-bearing:
 *
 *   - the **partial** unique index. One live row per user and client, so the write at token
 *     issuance can be an upsert; and a revoked row stays beside a later re-authorisation
 *     instead of blocking it. A plain unique index would force a choice between losing the
 *     history and refusing the re-authorisation.
 *   - the index on `oauth2_access_token.user_identifier`. That table is the bundle's and had an
 *     index on `client` alone; the screen added by this unit reads it by user on every load.
 *     The column belongs to the bundle's mapping, but an index is additive — nothing in the
 *     bundle declares one to contradict.
 */
final class Version20260927080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when an OAuth application was authorized and last used (#1308)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_grant (
              id UUID NOT NULL,
              user_id UUID NOT NULL,
              client_identifier VARCHAR(512) NOT NULL,
              scopes TEXT NOT NULL,
              authorized_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_oauth_grant_user ON oauth_grant (user_id)');
        $this->addSql('CREATE INDEX idx_oauth_grant_client ON oauth_grant (client_identifier)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_oauth_grant_live
              ON oauth_grant (user_id, client_identifier)
              WHERE revoked_at IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth_grant
            ADD
              CONSTRAINT fk_oauth_grant_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth_grant
            ADD
              CONSTRAINT fk_oauth_grant_client FOREIGN KEY (client_identifier) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql('CREATE INDEX idx_oauth2_access_token_user ON oauth2_access_token (user_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_oauth2_access_token_user');
        $this->addSql('ALTER TABLE oauth_grant DROP CONSTRAINT fk_oauth_grant_client');
        $this->addSql('ALTER TABLE oauth_grant DROP CONSTRAINT fk_oauth_grant_user');
        $this->addSql('DROP TABLE oauth_grant');
    }
}
