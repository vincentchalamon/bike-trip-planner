<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a client registered by metadata document must be read again (ADR-079 addendum).
 *
 * NULL for a client registered by hand: only the authorization listener writes it, from the
 * document's own max-age, and re-reads the document once it has passed.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expire the metadata of OAuth clients registered by document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client ADD metadata_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_client DROP metadata_expires_at');
    }
}
