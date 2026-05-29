<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use App\State\Account\AccountDeleteProcessor;
use App\State\Account\AccountExportProvider;

/**
 * GDPR self-service operations for the authenticated user (#549).
 *
 * - DELETE /users/me        right to erasure: anonymise the account, purge
 *   trips and preferences, revoke refresh tokens
 * - GET    /users/me/export right to portability: download a JSON archive of
 *   the profile, trips and their preferences
 *
 * The current user is always resolved from the security token, never from a
 * URL identifier, so there is no IDOR surface.
 */
#[ApiResource(
    shortName: 'Account',
    operations: [
        new Delete(
            uriTemplate: '/users/me',
            status: 204,
            security: "is_granted('ROLE_USER')",
            output: false,
            read: false,
            processor: AccountDeleteProcessor::class,
        ),
        new Get(
            uriTemplate: '/users/me/export',
            security: "is_granted('ROLE_USER')",
            provider: AccountExportProvider::class,
        ),
    ],
)]
final class Account
{
}
