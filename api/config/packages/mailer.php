<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
            'envelope' => [
                'sender' => 'noreply@bike-trip-planner.com',
            ],
            'headers' => [
                'From' => 'Bike Trip Planner <noreply@bike-trip-planner.com>',
            ],
        ],
    ]);
};
