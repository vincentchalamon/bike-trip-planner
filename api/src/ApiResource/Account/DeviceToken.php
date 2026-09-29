<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Enum\DevicePlatform;
use App\State\Account\DeviceTokenDeleteProcessor;
use App\State\Account\DeviceTokenRegisterProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Push-notification device-token registration for the authenticated user (epic #1051).
 *
 * - POST   /users/me/device-tokens         idempotent upsert of an FCM token bound
 *   to the current user (re-registering the same token does not duplicate; a token
 *   held by another account is reassigned). 201 on create, 200 on update.
 * - POST   /users/me/device-tokens/unregister  unregister a token owned by the
 *   current user, named in the body. The lookup is scoped to the caller's own tokens,
 *   so an unknown or foreign token is simply "not found" -> 404 (no object-level
 *   authorization to mask).
 *
 * The current user is always resolved from the security token, never from a URL
 * identifier (no IDOR surface). The token never rides in a URL: a path segment lands
 * in every access log on the way, and an FCM token is what a push is addressed to.
 */
#[ApiResource(
    shortName: 'DeviceToken',
    operations: [
        new Post(
            uriTemplate: '/users/me/device-tokens',
            // The register processor returns 201 on create and 200 on re-register;
            // this is the create-path default. Without it API Platform documents
            // the output:false operation as 204 No Content — wrong on both the
            // status and the JSON body the typed clients (pwa/mobile) consume.
            status: 201,
            openapi: new Operation(
                responses: [
                    '201' => new Response(
                        description: 'Device token registered',
                        content: new \ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'token' => ['type' => 'string'],
                                        'platform' => ['type' => 'string', 'enum' => ['android', 'ios']],
                                        'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '200' => new Response(
                        description: 'Device token re-registered (platform refreshed / reassigned)',
                        content: new \ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'token' => ['type' => 'string'],
                                        'platform' => ['type' => 'string', 'enum' => ['android', 'ios']],
                                        'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '422' => new Response(description: 'Missing or unknown platform'),
                    '409' => new Response(description: 'Concurrent registration of the same token; retry'),
                ],
            ),
            security: "is_granted('ROLE_USER')",
            output: false,
            read: false,
            processor: DeviceTokenRegisterProcessor::class,
        ),
        new Post(
            uriTemplate: '/users/me/device-tokens/unregister',
            status: 204,
            openapi: new Operation(
                responses: [
                    '204' => new Response(description: 'Device token unregistered'),
                    '404' => new Response(description: "Not among the current user's tokens"),
                ],
                summary: 'Unregisters a device token of the current user.',
                description: 'Unregisters a device token of the current user. The token is sent in the body, never in the URL.',
            ),
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['device-token:unregister']],
            output: false,
            read: false,
            processor: DeviceTokenDeleteProcessor::class,
        ),
    ],
)]
final class DeviceToken
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        #[Assert\NotBlank(groups: ['Default', 'device-token:unregister'])]
        #[Assert\Length(max: 255, groups: ['Default', 'device-token:unregister'])]
        public string $token = '',
        #[Assert\NotNull]
        public ?DevicePlatform $platform = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}
