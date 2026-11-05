<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bind a magic link to the client that asked for it (SEC-15, ADR-023 addendum).
 *
 * `nonce_hash` is the SHA-256 of a secret the requesting client keeps. A verify that presents
 * it signs in at once; any other verify gets the account email back and must confirm. Null
 * for a link no client asked for (a CLI invitation), which therefore always confirms.
 */
final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind a magic link to the requesting client (SEC-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_link ADD nonce_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_link DROP nonce_hash');
    }
}
