<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Llm\AiProvider;
use App\State\Account\AiSettingsClearProcessor;
use App\State\Account\AiSettingsProcessor;
use App\State\Account\AiSettingsProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-user AI configuration (ADR-042): the chosen cloud provider and its API
 * token (bring-your-own-token). AI is opt-in — without a configured token every
 * AI feature stays disabled.
 *
 * - GET    /users/me/ai-settings  current provider + whether a token is stored
 * - PUT    /users/me/ai-settings  set provider + token (token format validated)
 * - DELETE /users/me/ai-settings  clear both
 *
 * The token is write-only: it is encrypted at rest and the API never returns it,
 * only the boolean {@see $tokenConfigured}. The current user is resolved from the
 * security token, never from a URL identifier (no IDOR surface).
 */
#[ApiResource(
    shortName: 'AiSettings',
    operations: [
        new Get(
            uriTemplate: '/users/me/ai-settings',
            security: "is_granted('ROLE_USER')",
            provider: AiSettingsProvider::class,
        ),
        new Put(
            uriTemplate: '/users/me/ai-settings',
            security: "is_granted('ROLE_USER')",
            read: false,
            processor: AiSettingsProcessor::class,
        ),
        new Delete(
            uriTemplate: '/users/me/ai-settings',
            status: 204,
            security: "is_granted('ROLE_USER')",
            output: false,
            read: false,
            processor: AiSettingsClearProcessor::class,
        ),
    ],
)]
final class AiSettings
{
    /**
     * Chosen provider, or null when AI is not configured.
     */
    #[Assert\Choice(callback: [AiProvider::class, 'values'], message: 'Unknown AI provider.')]
    public ?string $provider = null;

    /**
     * Write-only: the stored token is never serialised back, only whether one is set.
     */
    #[ApiProperty(readable: false)]
    #[Assert\Length(max: 500)]
    public ?string $token = null;

    /**
     * Read-only signal that a (non-empty) token is stored.
     */
    #[ApiProperty(writable: false)]
    public bool $tokenConfigured = false;
}
