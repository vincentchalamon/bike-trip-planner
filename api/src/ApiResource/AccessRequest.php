<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\RetryAfter;
use App\State\AccessRequestCreateProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'AccessRequest',
    operations: [
        new Post(
            uriTemplate: '/access-requests',
            status: 202,
            openapi: new Operation(
                responses: [
                    429 => new Response(description: 'Rate limit reached', headers: new \ArrayObject(RetryAfter::HEADERS)),
                ],
            ),
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
