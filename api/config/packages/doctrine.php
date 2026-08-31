<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use MartinGeorgiev\Doctrine\DBAL\Types\Jsonb;
use MartinGeorgiev\Doctrine\DBAL\Types\TextArray;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('doctrine', [
        'dbal' => [
            'types' => [
                'jsonb' => Jsonb::class,
                'text[]' => TextArray::class,
            ],
            // Two connections since the PG split (ADR-060): `default` is the
            // per-stack PG-app (the `public` schema — trips, stages, auth), owned
            // and migrated on boot by this app; `reference` is the shared,
            // read-only PG-référence (the `osm` / `tourism` / `provisioner`
            // schemas), written only by the provisioner and read here via raw SQL.
            // There is no cross-schema FK/join between the two, so a physical split
            // onto separate hosts is transparent to the app.
            'default_connection' => 'default',
            'connections' => [
                'default' => [
                    'url' => '%env(resolve:DATABASE_URL)%',
                    'profiling_collect_backtrace' => '%kernel.debug%',
                    // PostGIS creates the spatial_ref_sys table in the public schema.
                    // Exclude it from the schema tool so doctrine:migrations:diff and
                    // schema:validate don't emit a DROP for it. The Tier-1 osm2pgsql
                    // tables live in their own schema, also outside Doctrine (ADR-040).
                    'schema_filter' => '~^(?!spatial_ref_sys)~',
                    'mapping_types' => [
                        'jsonb' => 'jsonb',
                        '_text' => 'text[]',
                        'text[]' => 'text[]',
                    ],
                ],
                // Shared read-only reference index (ADR-040/060). No ORM entity maps
                // to it — every reader is a raw-SQL repository injected with this
                // connection (App\Osm\*, App\Tourism\*, App\InRide\InRidePoiRepository,
                // App\Command\NotifyZoneOpenedCommand), so there is no second entity
                // manager. The jsonb / text[] mapping types are duplicated here so
                // those repositories' result-set conversions behave identically.
                'reference' => [
                    'url' => '%env(resolve:REFERENCE_DATABASE_URL)%',
                    'profiling_collect_backtrace' => '%kernel.debug%',
                    'schema_filter' => '~^(?!spatial_ref_sys)~',
                    'mapping_types' => [
                        'jsonb' => 'jsonb',
                        '_text' => 'text[]',
                        'text[]' => 'text[]',
                    ],
                ],
            ],
        ],
        'orm' => [
            'validate_xml_mapping' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'identity_generation_preferences' => [
                PostgreSQLPlatform::class => 'identity',
            ],
            'auto_mapping' => true,
            'mappings' => [
                'App' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'App\Entity',
                    'alias' => 'App',
                ],
                'ApiResource' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/ApiResource',
                    'prefix' => 'App\ApiResource',
                    'alias' => 'ApiResource',
                ],
            ],
            'controller_resolver' => [
                'auto_mapping' => false,
            ],
        ],
    ]);

    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('doctrine', [
            'dbal' => [
                'connections' => [
                    'default' => [
                        'dbname_suffix' => '_test%env(default::TEST_TOKEN)%',
                    ],
                    // In test/CI a single Postgres backs both connections (the
                    // reference URL defaults to DATABASE_URL, see services.php), so
                    // the reference connection must take the same `_test` suffix or
                    // it would target the non-test database.
                    'reference' => [
                        'dbname_suffix' => '_test%env(default::TEST_TOKEN)%',
                    ],
                ],
            ],
        ]);
    }

    if ('prod' === $containerConfigurator->env()) {
        $containerConfigurator->extension('doctrine', [
            'orm' => [
                'query_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.system_cache_pool',
                ],
                'result_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.result_cache_pool',
                ],
            ],
        ]);

        $containerConfigurator->extension('framework', [
            'cache' => [
                'pools' => [
                    'doctrine.result_cache_pool' => [
                        'adapter' => 'cache.app',
                    ],
                    'doctrine.system_cache_pool' => [
                        // Redis, not cache.system (PhpFiles): the DQL query cache is
                        // written at runtime on parse miss, which would fail under
                        // read_only (no var volume, see ADR-037 / #728).
                        'adapter' => 'cache.adapter.redis',
                    ],
                ],
            ],
        ]);
    }
};
