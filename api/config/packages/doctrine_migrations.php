<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    // Boot migration path: the public (PG-app) history only. In prod this runs on
    // the default connection at container boot (MIGRATIONS_ON_BOOT); the reference
    // schema is owned by the provisioner (ADR-060) and never migrated onto PG-app.
    $containerConfigurator->extension('doctrine_migrations', [
        'migrations_paths' => [
            'DoctrineMigrations' => '%kernel.project_dir%/migrations',
        ],
        'enable_profiler' => false,
    ]);

    // In dev/CI a single Postgres backs both connections, so the reference history is
    // folded into the boot migrate here: `make start-dev` and Foundry's `migrate`
    // reset then create the osm/tourism/provisioner schemas (+ PostGIS) on that one
    // database, which the app reads through the `reference` connection and which
    // `make provision` writes. This env guard is what keeps the reference DDL OFF the
    // per-stack PG-app in prod (where the provisioner owns it).
    if (\in_array($containerConfigurator->env(), ['dev', 'test'], true)) {
        $containerConfigurator->extension('doctrine_migrations', [
            'migrations_paths' => [
                'DoctrineMigrations\\Reference' => '%kernel.project_dir%/migrations/reference',
            ],
        ]);
    }
};
