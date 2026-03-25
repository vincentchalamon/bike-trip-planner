<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('security', [
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => App\Entity\User::class,
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
            'auth' => [
                'pattern' => '^/(api/auth/request-link|api/auth/refresh|auth/verify)',
                'stateless' => true,
                'security' => false,
            ],
            'api' => [
                'pattern' => '^/',
                'stateless' => true,
                'provider' => 'app_user_provider',
                'jwt' => [],
            ],
        ],
        'access_control' => [
            ['path' => '^/api/auth/request-link', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/auth/refresh', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/auth/verify', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/auth/logout', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['path' => '^/api/docs', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api', 'roles' => 'PUBLIC_ACCESS'],
        ],
    ]);
};
