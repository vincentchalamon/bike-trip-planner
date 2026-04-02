<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401142448 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TripShare: add deleted_at for soft delete, remove expires_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip_share ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE trip_share DROP expires_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip_share ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE trip_share DROP deleted_at');
    }
}
