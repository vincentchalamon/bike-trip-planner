<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Auth\AuthVerifyProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'AuthVerify',
    operations: [
        new Post(
            uriTemplate: '/auth/verify',
            status: 200,
            output: false,
            processor: AuthVerifyProcessor::class,
        ),
    ],
)]
final class AuthVerify
{
    public function __construct(
        #[Assert\NotBlank]
        public string $token = '',
    ) {
    }
}
