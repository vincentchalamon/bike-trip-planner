<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Single baseline migration (pre-launch migration reset, sprint 51 follow-up).
 *
 * Replaces the 43 incremental migrations accumulated during development. No `v*`
 * tag has ever shipped and no production database exists, so collapsing the
 * create → alter → drop churn into one authoritative baseline is safe — same
 * pre-launch rationale as the #937 exception recorded in ADR-032, and recorded
 * itself in that ADR's "pre-launch migration baseline reset" addendum.
 *
 * `up()` executes `schema/public_schema.sql`, the `public` (PG-app) half of the
 * former single baseline: trips, stages and auth. Since the PG split (ADR-060) the
 * `osm` / `tourism` / `provisioner` schemas + the PostGIS extension no longer live
 * here — they moved to `schema/reference_schema.sql` on the shared read-only
 * PG-référence, applied by the provisioner (and by the reference Doctrine migration
 * in test/CI). This connection carries NO PostGIS dependency.
 *
 * Operational note: existing local dev databases predate this reset and still
 * record the 43 old versions, so they cannot adopt the baseline incrementally.
 * Recreate them (`make start-dev` on a fresh `database` volume). Fresh databases
 * (CI, new checkouts) run this one migration and are correct.
 */
final class Version20260807130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline public (PG-app) schema: pre-launch reset, split from the reference schema (ADR-060)';
    }

    public function up(Schema $schema): void
    {
        $sql = file_get_contents(__DIR__.'/schema/public_schema.sql');
        \assert(false !== $sql);

        // DBAL runs each addSql() as a prepared statement, which rejects a
        // multi-command string, so the dump is split into individual statements.
        // The file is a pg_dump --schema-only with no functions / dollar-quoted
        // bodies, so once the pg_dump section-header comment lines are stripped
        // every ';' terminates a statement — a plain split is safe here.
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
        \assert(null !== $withoutComments);

        foreach (explode(';', $withoutComments) as $statement) {
            $statement = trim($statement);
            if ('' !== $statement) {
                $this->addSql($statement);
            }
        }
    }

    /**
     * Reverts to an empty PG-app database. This baseline now owns only the
     * `public` schema (the reference schemas belong to the reference migration),
     * so its inverse resets `public` alone — no data is at stake pre-launch.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA public CASCADE');
        $this->addSql('CREATE SCHEMA public');
    }
}
