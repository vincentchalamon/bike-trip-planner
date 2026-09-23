<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enables pg_stat_statements so slow queries can be named rather than guessed (#510).
 *
 * The extension only records anything if the library is preloaded at server start, which
 * `compose.yaml` now does — creating it without that flag is a silent no-op. Read it with
 * `make perf-db-report`.
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the pg_stat_statements extension (#510)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
}
