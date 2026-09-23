<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the trip list by the shape of the query that reads it.
 *
 * `GET /trips` filters on the owner and orders by `created_at` descending, and `trip` carried
 * only `idx_trip_user (user_id)` — enough to find the rows, not enough to avoid sorting them.
 * The composite serves both halves and subsumes the single-column index, which goes with it.
 */
final class Version20260923160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index trip on (user_id, created_at DESC), the trip list query shape';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_trip_user_created_at ON trip (user_id, created_at DESC)');
        $this->addSql('DROP INDEX idx_trip_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_trip_user ON trip (user_id)');
        $this->addSql('DROP INDEX idx_trip_user_created_at');
    }
}
