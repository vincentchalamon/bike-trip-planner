<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthRequestLinkProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'AuthRequestLink',
    operations: [
        new Post(
            uriTemplate: '/auth/request-link',
            status: 200,
            output: false,
            processor: AuthRequestLinkProcessor::class,
        ),
    ],
)]
final class AuthRequestLink
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',
    ) {
    }
}
