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
                        'adapter' => 'cache.system',
                    ],
                ],
            ],
        ]);
    }
};
