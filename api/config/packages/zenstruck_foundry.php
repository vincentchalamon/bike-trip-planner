<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    if (\in_array($containerConfigurator->env(), ['dev', 'test'], true)) {
        $containerConfigurator->extension('zenstruck_foundry', [
            'persistence' => [
                'flush_once' => true,
            ],
        ]);
    }
};
