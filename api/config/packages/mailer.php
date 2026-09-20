<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
            // Applied to every message that carries no From of its own, so the
            // sender address lives in one place instead of at each send site.
            'headers' => [
                'from' => '"Bike Trip Planner" <%env(MAILER_SENDER_EMAIL)%>',
            ],
        ],
    ]);
};
