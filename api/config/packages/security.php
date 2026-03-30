<?php

declare(strict_types=1);

use App\Entity\User;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('security', [
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js|docs)/',
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
            ['path' => '^/auth/(request-link|refresh|verify)$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ],
    ]);
};
