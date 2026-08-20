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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-category server-push opt-in for the authenticated user (#1124).
 *
 * - GET /users/me/notification-preferences         the effective opt-in for every
 *   category (stored override, or the category default when unset).
 * - PUT /users/me/notification-preferences/{category}  set the opt-in for one
 *   category. Body: {"enabled": true|false} — `enabled` is required (a body
 *   omitting it is 422, never a silent opt-out). An unknown category is 404.
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
        // Required on PUT: a body omitting it must 422, not silently default to
        // false and opt the user out of a default-ON category (weatherSafety /
        // analysisDone). Nullable so a missing field denormalizes to null and trips
        // NotNull, rather than defaulting to false.
        #[Assert\NotNull]
        public ?bool $enabled = null,
    ) {
    }
}
