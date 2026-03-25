<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthLogoutProcessor;

#[ApiResource(
    shortName: 'AuthLogout',
    operations: [
        new Post(
            uriTemplate: '/auth/logout',
            status: 204,
            input: false,
            output: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: AuthLogoutProcessor::class,
        ),
    ],
)]
final class AuthLogout
{
}
