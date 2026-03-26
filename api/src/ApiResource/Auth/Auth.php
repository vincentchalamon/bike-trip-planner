<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthLogoutProcessor;
use App\State\Auth\AuthRefreshProcessor;
use App\State\Auth\AuthRequestLinkProcessor;
use App\State\Auth\AuthVerifyProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Auth',
    operations: [
        new Post(
            uriTemplate: '/auth/request-link',
            status: 202,
            security: 'is_granted("PUBLIC_ACCESS")',
            validationContext: ['groups' => ['auth:request-link']],
            output: false,
            processor: AuthRequestLinkProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/verify',
            status: 200,
            security: 'is_granted("PUBLIC_ACCESS")',
            validationContext: ['groups' => ['auth:verify']],
            output: false,
            processor: AuthVerifyProcessor::class,
        ),
        new Post(
            uriTemplate: '/auth/refresh',
            status: 200,
            security: 'is_granted("PUBLIC_ACCESS")',
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
    ],
)]
final class Auth
{
    public const string REFRESH_TOKEN_COOKIE = 'refresh_token';

    public function __construct(
        #[Assert\NotBlank(groups: ['auth:request-link'])]
        #[Assert\Email(groups: ['auth:request-link'])]
        public string $email = '',
        #[Assert\NotBlank(groups: ['auth:verify'])]
        public string $token = '',
    ) {
    }
}
