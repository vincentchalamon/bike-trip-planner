<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the device_token table (push-notification registration, epic #1051).
 *
 * One row per FCM token, globally unique so a device is bound to a single account
 * at a time. FK to `user` with ON DELETE CASCADE: erasing an account drops its
 * device tokens.
 */
final class Version20260819120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create device_token table (push-notification registration, epic #1051)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE device_token (
            id uuid NOT NULL,
            user_id uuid NOT NULL,
            token character varying(255) NOT NULL,
            platform character varying(255) NOT NULL,
            created_at timestamp(0) without time zone NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_token_token ON device_token (token)');
        $this->addSql('CREATE INDEX idx_device_token_user ON device_token (user_id)');
        $this->addSql("COMMENT ON COLUMN device_token.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE device_token ADD CONSTRAINT fk_device_token_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE device_token');
    }
}
