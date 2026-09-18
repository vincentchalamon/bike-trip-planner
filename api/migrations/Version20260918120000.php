<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the trip structural version counter (ADR-066).
 *
 * Bumped on every write of the stage collection and on every settings change that
 * invalidates in-flight computations. Replaces the Redis-held generation counter, whose
 * 30-minute TTL let it vanish — and then restart from 1, so it could go backwards while
 * messages were still in flight (#252, RC1 and RC5).
 *
 * Existing rows start at 1, which is what a freshly created trip gets: the counter only
 * has to be monotonic per trip, never comparable across trips.
 */
final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trip.version, the structural version counter (ADR-066)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trip DROP version');
    }
}
