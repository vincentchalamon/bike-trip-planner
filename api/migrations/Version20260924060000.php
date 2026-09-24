<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The four tables the OAuth authorization server persists into (ADR-079).
 *
 * `oauth2_client.identifier` is VARCHAR(512), not the bundle's VARCHAR(32): a client is
 * named by the HTTPS URL its Client ID Metadata Document is served from. The three token
 * tables inherit that width on their foreign key.
 *
 * No `oauth2_device_code` table: the device code grant is disabled, and the bundle's
 * mapping driver leaves the entity unmapped when it is.
 *
 * Note for later: `user_identifier` is VARCHAR(128) and holds the user's email, which the
 * application stores in VARCHAR(180). An address longer than 128 characters cannot be
 * issued a token. The column belongs to the bundle's own mapping, so widening it here
 * would be reverted by the next `doctrine:migrations:diff`; the authorization endpoint
 * refuses such an account up front instead.
 */
final class Version20260924060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the OAuth authorization server tables (ADR-079)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_client (
              identifier VARCHAR(512) NOT NULL,
              name VARCHAR(128) NOT NULL,
              secret VARCHAR(128) DEFAULT NULL,
              redirect_uris TEXT DEFAULT NULL,
              grants TEXT DEFAULT NULL,
              scopes TEXT DEFAULT NULL,
              active BOOLEAN NOT NULL,
              allow_plain_text_pkce BOOLEAN DEFAULT false NOT NULL,
              PRIMARY KEY (identifier)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_access_token (
              identifier CHAR(80) NOT NULL,
              expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              user_identifier VARCHAR(128) DEFAULT NULL,
              scopes TEXT DEFAULT NULL,
              revoked BOOLEAN NOT NULL,
              client VARCHAR(512) NOT NULL,
              PRIMARY KEY (identifier)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_454D9673C7440455 ON oauth2_access_token (client)');

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_authorization_code (
              identifier CHAR(80) NOT NULL,
              expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              user_identifier VARCHAR(128) DEFAULT NULL,
              scopes TEXT DEFAULT NULL,
              revoked BOOLEAN NOT NULL,
              client VARCHAR(512) NOT NULL,
              PRIMARY KEY (identifier)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_509FEF5FC7440455 ON oauth2_authorization_code (client)');

        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_refresh_token (
              identifier CHAR(80) NOT NULL,
              expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              revoked BOOLEAN NOT NULL,
              access_token CHAR(80) DEFAULT NULL,
              PRIMARY KEY (identifier)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_4DD90732B6A2DD68 ON oauth2_refresh_token (access_token)');

        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_access_token
            ADD
              CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_authorization_code
            ADD
              CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              oauth2_refresh_token
            ADD
              CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE
            SET
              NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP CONSTRAINT FK_4DD90732B6A2DD68');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP CONSTRAINT FK_509FEF5FC7440455');
        $this->addSql('ALTER TABLE oauth2_access_token DROP CONSTRAINT FK_454D9673C7440455');
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_client');
    }
}
