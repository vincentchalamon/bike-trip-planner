<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `replaced_by_token` to `refresh_token` for the rotation grace window (#649).
 *
 * On rotation the old token is no longer deleted: its lifetime is cut to a short
 * grace window and it points at its successor. A rapid reload that re-sends the
 * pre-rotation cookie then resolves to the successor (idempotent) instead of a
 * 401 that would destroy the session.
 */
final class Version20260610120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add replaced_by_token to refresh_token for rotation grace window (#649)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token ADD replaced_by_token VARCHAR(128) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE refresh_token DROP replaced_by_token
            SQL);
    }
}
