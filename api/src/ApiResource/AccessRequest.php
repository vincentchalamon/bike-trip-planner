<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\AccessRequestCreateProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'AccessRequest',
    operations: [
        new Post(
            uriTemplate: '/access-requests',
            status: 202,
            validationContext: ['groups' => ['access_request:create']],
            output: false,
            processor: AccessRequestCreateProcessor::class,
        ),
    ],
)]
final class AccessRequest
{
    public function __construct(
        #[Assert\NotBlank(groups: ['access_request:create'])]
        #[Assert\Email(groups: ['access_request:create'])]
        public string $email = '',
    ) {
    }
}
