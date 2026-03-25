<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthRefreshProcessor;

#[ApiResource(
    shortName: 'AuthRefresh',
    operations: [
        new Post(
            uriTemplate: '/auth/refresh',
            status: 200,
            input: false,
            output: false,
            processor: AuthRefreshProcessor::class,
        ),
    ],
)]
final class AuthRefresh
{
}
