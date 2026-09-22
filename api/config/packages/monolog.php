<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('monolog', [
        'channels' => [
            'deprecation',
        ],
    ]);
    if ('dev' === $containerConfigurator->env()) {
        $containerConfigurator->extension('monolog', [
            'handlers' => [
                'main' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'debug',
                    'channels' => [
                        '!event',
                    ],
                ],
                'console' => [
                    'type' => 'console',
                    'process_psr_3_messages' => false,
                    'channels' => [
                        '!event',
                        '!doctrine',
                        '!console',
                    ],
                ],
            ],
        ]);
    }

    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('monolog', [
            'handlers' => [
                'main' => [
                    'type' => 'fingers_crossed',
                    'action_level' => 'error',
                    'handler' => 'nested',
                    'excluded_http_codes' => [
                        404,
                        405,
                    ],
                    'channels' => [
                        '!event',
                    ],
                ],
                'nested' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/%kernel.environment%.log',
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    if ('prod' === $containerConfigurator->env()) {
        $containerConfigurator->extension('monolog', [
            'handlers' => [
                'main' => [
                    'type' => 'fingers_crossed',
                    'action_level' => 'error',
                    'handler' => 'nested',
                    'excluded_http_codes' => [
                        404,
                        405,
                    ],
                    'channels' => [
                        '!deprecation',
                    ],
                    'buffer_size' => 50,
                ],
                'nested' => [
                    'type' => 'stream',
                    'path' => 'php://stderr',
                    'level' => 'debug',
                    'formatter' => 'monolog.formatter.json',
                ],
                // Everything the application deliberately logs below `error` was buffered
                // by `main` and dropped unless an error happened to land in the same
                // 50-record window. That silently included the only instrumentation the
                // async tier has: AbstractTripMessageHandler::executeWithTracking() times
                // every computation and logs the duration at `info` — measured, then
                // thrown away in production.
                //
                // `main` is left as it is, so application errors keep the buffered context
                // that precedes them. The price is that an `error` on the `app` channel is
                // emitted twice, once here and once in the flush. Both lines are JSON on
                // stderr and carry the same request_id (CorrelationIdProcessor), so a
                // collector deduplicates them; losing every info line was the worse trade.
                'app' => [
                    'type' => 'stream',
                    'path' => 'php://stderr',
                    'level' => 'info',
                    'channels' => [
                        'app',
                    ],
                    'formatter' => 'monolog.formatter.json',
                ],
                'console' => [
                    'type' => 'console',
                    'process_psr_3_messages' => false,
                    'channels' => [
                        '!event',
                        '!doctrine',
                    ],
                ],
                'deprecation' => [
                    'type' => 'stream',
                    'channels' => [
                        'deprecation',
                    ],
                    'path' => 'php://stderr',
                    'formatter' => 'monolog.formatter.json',
                ],
            ],
        ]);
    }
};
