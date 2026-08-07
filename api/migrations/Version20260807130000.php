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
 * `up()` executes `schema/baseline_schema.sql`, a `pg_dump --schema-only` of a
 * database with every pre-reset migration applied. The dump was verified to
 * reproduce that schema byte-for-byte (a fresh DB loaded from the SQL re-dumps
 * identically). It carries the full DDL the ORM mappings do not describe — the
 * PostGIS extension, the `osm` / `tourism` / `provisioner` schemas, GiST indexes
 * and CHECK constraints — which a schema:create/diff baseline would have lost.
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
        return 'Baseline schema: pre-launch reset collapsing the 43 development migrations into one';
    }

    public function up(Schema $schema): void
    {
        $sql = file_get_contents(__DIR__.'/schema/baseline_schema.sql');
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
     * Reverts to an empty database. The baseline creates the whole schema, so
     * its inverse drops the custom schemas and resets `public` — no data is at
     * stake pre-launch.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS osm CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS tourism CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS provisioner CASCADE');
        $this->addSql('DROP SCHEMA public CASCADE');
        $this->addSql('CREATE SCHEMA public');
    }
}
