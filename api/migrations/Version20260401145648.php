<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401145648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TripShare: add short_code column for URL shortener';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE trip_share ADD short_code VARCHAR(8) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE trip_share SET short_code = substr(md5(token), 1, 8) WHERE short_code = ''");
        $this->addSql('CREATE UNIQUE INDEX uniq_trip_share_short_code ON trip_share (short_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_trip_share_short_code');
        $this->addSql('ALTER TABLE trip_share DROP short_code');
    }
}
