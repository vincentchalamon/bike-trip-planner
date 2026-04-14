<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

final readonly class AlertAction
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ApiProperty(description: 'Type of action: auto_fix, detour, navigate, dismiss.')]
        public AlertActionKind $kind,
        #[ApiProperty(description: 'Human-readable label for the action button.')]
        public string $label,
        #[ApiProperty(
            description: 'Machine-readable payload for the action.',
            openapiContext: ['type' => 'object', 'additionalProperties' => true],
        )]
        public array $payload = [],
    ) {
    }
}
