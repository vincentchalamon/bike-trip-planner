<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `deleted_at` column to `user` for GDPR account anonymisation (#549).
 *
 * The right-to-erasure flow soft-deletes the account by stamping `deleted_at`
 * and anonymising the email, while purging trips and revoking refresh tokens.
 */
final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at column to user for GDPR account anonymisation (#549)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN "user".deleted_at IS '(DC2Type:datetime_immutable)'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user" DROP deleted_at
            SQL);
    }
}
