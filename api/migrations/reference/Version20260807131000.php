<?php

declare(strict_types=1);

namespace DoctrineMigrations\Reference;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline of the shared read-only PG-référence (ADR-060): the `osm` / `tourism`
 * reference index, the `provisioner` bookkeeping schema and the PostGIS extension.
 *
 * Its own namespace (`DoctrineMigrations\Reference`) and directory, so it can be run
 * as a separate history against a physically-separate PG-référence in production
 * (config/migrations_reference.php, own version table). In production the reference
 * schema is normally applied by the provisioner instead (schema/reference_schema.sql
 * via psql before the first import, docker-entrypoint.sh); this migration is NOT run
 * on the per-stack PG-app.
 *
 * In dev/CI a single Postgres backs both connections, so this migration is folded
 * into the default boot history (config/packages/doctrine_migrations.php registers
 * this path for dev/test only): `make start-dev` and Foundry's `migrate` reset then
 * create the reference tables the functional tests read (e.g. HealthControllerTest).
 *
 * `up()` executes the same idempotent `schema/reference_schema.sql` the provisioner
 * applies, so the two executors can never drift.
 */
final class Version20260807131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline reference (PG-référence) schema: osm/tourism/provisioner + PostGIS (ADR-060)';
    }

    public function up(Schema $schema): void
    {
        $sql = file_get_contents(__DIR__.'/../schema/reference_schema.sql');
        \assert(false !== $sql);

        // Same split strategy as the public baseline: strip pg comment lines, then
        // split on ';'. The file carries no functions / dollar-quoted bodies, so a
        // plain split is safe and each statement runs as its own prepared statement.
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
        \assert(null !== $withoutComments);

        foreach (explode(';', $withoutComments) as $statement) {
            $statement = trim($statement);
            if ('' !== $statement) {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA IF EXISTS osm CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS tourism CASCADE');
        $this->addSql('DROP SCHEMA IF EXISTS provisioner CASCADE');
    }
}
