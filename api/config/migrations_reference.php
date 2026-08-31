<?php

declare(strict_types=1);

/*
 * Standalone migration configuration for the reference (PG-référence) history (ADR-060).
 *
 * Its own version table so the reference DDL is tracked independently of the PG-app
 * history. In production the reference schema is normally applied by the provisioner
 * (schema/reference_schema.sql via psql), but this file is the supported way to
 * version a physically-separate PG-référence with Doctrine instead:
 *
 *   bin/console doctrine:migrations:migrate \
 *       --configuration=config/migrations_reference.php --conn reference
 *
 * In dev/CI a single Postgres backs both connections, so the reference history is
 * folded into the boot migrate instead (see config/packages/doctrine_migrations.php)
 * and this file is not used.
 */

return [
    'table_storage' => [
        'table_name' => 'doctrine_migration_reference_versions',
    ],
    'migrations_paths' => [
        'DoctrineMigrations\\Reference' => __DIR__.'/../migrations/reference',
    ],
    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => true,
];
