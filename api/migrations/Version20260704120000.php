<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encrypts refresh tokens at rest (SEC-003).
 *
 * The token column now stores a reversible libsodium ciphertext (see
 * RefreshTokenEncryptor) instead of the plaintext credential, and rows are
 * looked up by a deterministic `token_digest` (sha256). Existing rows hold
 * plaintext incompatible with the new format, so they are purged — active
 * sessions transparently re-authenticate via the refresh flow / magic link.
 */
final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypt refresh tokens at rest + look up by digest (SEC-003)';
    }

    public function up(Schema $schema): void
    {
        // Plaintext rows are incompatible with the encrypted format; purge them.
        $this->addSql(<<<'SQL'
            DELETE FROM refresh_token
            SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX uniq_refresh_token_token
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ALTER COLUMN token TYPE TEXT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ADD token_digest VARCHAR(64) NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_refresh_token_digest ON refresh_token (token_digest)
            SQL);
        // replaced_by_token now holds the successor's 64-char digest, not the token.
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ALTER COLUMN replaced_by_token TYPE VARCHAR(64)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM refresh_token
            SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX uniq_refresh_token_digest
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token DROP token_digest
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ALTER COLUMN token TYPE VARCHAR(128)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_refresh_token_token ON refresh_token (token)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ALTER COLUMN replaced_by_token TYPE VARCHAR(128)
            SQL);
    }
}
