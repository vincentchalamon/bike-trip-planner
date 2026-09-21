<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    if ('dev' === $containerConfigurator->env()) {
        $containerConfigurator->extension('web_profiler', [
            'toolbar' => true,
        ]);
        // Load-bearing even though it only restates the default: the profiler is
        // enabled by the *presence* of `framework.profiler`, and this is its only
        // declaration in dev. Dropping it also drops every profiler-only service
        // (mailer.message_logger_listener among them).
        $containerConfigurator->extension('framework', [
            'profiler' => null,
        ]);
    }

    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('framework', [
            'profiler' => [
                'collect' => false,
            ],
        ]);
    }
};
