<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthLogoutProcessor;
use App\State\Auth\AuthRefreshProcessor;
use App\State\Auth\AuthRequestLinkProcessor;
use App\State\Auth\AuthSessionProvider;
use App\State\Auth\AuthVerifyProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Auth',
    operations: [
        new Post(
            uriTemplate: '/auth/request-link',
            status: 202,
            validationContext: ['groups' => ['auth:request-link']],
            output: false,
            processor: AuthRequestLinkProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/verify',
            validationContext: ['groups' => ['auth:verify']],
            output: false,
            processor: AuthVerifyProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/refresh',
            input: false,
            output: false,
            processor: AuthRefreshProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/logout',
            status: 204,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            input: false,
            output: false,
            processor: AuthLogoutProcessor::class,
        ),
        new Get(
            uriTemplate: '/auth/session',
            // Per-user PII keyed on the refresh_token cookie: must never be
            // shared-cached, and any cache must vary by Cookie (this endpoint is
            // reachable on the public origin).
            cacheHeaders: ['vary' => ['Cookie'], 'public' => false],
            output: AuthSession::class,
            provider: AuthSessionProvider::class,
        ),
    ],
)]
final class Auth
{
    public function __construct(
        #[Assert\NotBlank(groups: ['auth:request-link'])]
        #[Assert\Email(groups: ['auth:request-link'])]
        public string $email = '',
        #[Assert\NotBlank(groups: ['auth:verify'])]
        public string $token = '',
    ) {
    }
}
