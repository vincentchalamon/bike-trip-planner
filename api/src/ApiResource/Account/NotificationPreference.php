<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Enum\NotificationCategory;
use App\State\Account\NotificationPreferenceProvider;
use App\State\Account\NotificationPreferenceUpdateProcessor;

/**
 * Per-category server-push opt-in for the authenticated user (#1124).
 *
 * - GET /users/me/notification-preferences         the effective opt-in for every
 *   category (stored override, or the category default when unset).
 * - PUT /users/me/notification-preferences/{category}  set the opt-in for one
 *   category. Body: {"enabled": true|false}. An unknown category is 404.
 *
 * Defaults: weatherSafety and analysisDone are ON, zoneOpening is OFF (opt-in).
 * The current user is always resolved from the security token, never a URL id.
 */
#[ApiResource(
    shortName: 'NotificationPreference',
    operations: [
        new GetCollection(
            uriTemplate: '/users/me/notification-preferences',
            security: "is_granted('ROLE_USER')",
            provider: NotificationPreferenceProvider::class,
        ),
        new Put(
            uriTemplate: '/users/me/notification-preferences/{category}',
            security: "is_granted('ROLE_USER')",
            read: false,
            processor: NotificationPreferenceUpdateProcessor::class,
        ),
    ],
)]
final class NotificationPreference
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public ?NotificationCategory $category = null,
        public bool $enabled = false,
    ) {
    }
}
