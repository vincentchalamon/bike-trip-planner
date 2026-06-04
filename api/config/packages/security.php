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
                // Object-level authz denials (TRIP_*) -> 404, not 403, to avoid
                // leaking trip existence by enumeration (ADR-038). Only fires for
                // authenticated requests; anonymous still gets 401 via the entry point.
                'access_denied_handler' => HideForbiddenAsNotFoundHandler::class,
            ],
        ],
        'access_control' => [
            ['path' => '^/api/health(z)?$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/docs', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/auth/(request-link|refresh|verify)$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/access-requests(/verify)?$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/auth/logout', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['path' => '^/s/', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ],
    ]);
};
