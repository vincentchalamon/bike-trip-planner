<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the notification_preference table (server-push categories, #1124).
 *
 * One row per (user, category) opt-in. Absence of a row means "use the category
 * default", so only overrides are stored. FK to `user` with ON DELETE CASCADE:
 * erasing an account drops its preferences.
 */
final class Version20260819130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_preference table (server-push categories, #1124)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_preference (
            id uuid NOT NULL,
            user_id uuid NOT NULL,
            category character varying(255) NOT NULL,
            enabled boolean NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference_user_category ON notification_preference (user_id, category)');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT fk_notification_preference_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_preference');
    }
}
