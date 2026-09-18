<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\State\Account\AccountDeleteProcessor;
use App\State\Account\AccountExportProvider;
use App\State\Account\AccountMeProvider;
use App\State\Account\AccountUpdateProcessor;

/**
 * GDPR self-service operations for the authenticated user (#549).
 *
 * - GET    /users/me        the current user's profile ({ userId, email, locale })
 * - PATCH  /users/me        change the account preferences; only `locale` today.
 *   It is the language the server renders for the user (emails) and passes to
 *   third parties when enriching a trip, so it must be a stored preference rather
 *   than an `Accept-Language` header (ADR-063).
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
        new Get(
            uriTemplate: '/users/me',
            security: "is_granted('ROLE_USER')",
            output: AccountMe::class,
            provider: AccountMeProvider::class,
        ),
        new Patch(
            uriTemplate: '/users/me',
            security: "is_granted('ROLE_USER')",
            input: AccountUpdate::class,
            output: AccountMe::class,
            read: false,
            processor: AccountUpdateProcessor::class,
        ),
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
