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
        #[ApiProperty(description: 'Type of action: auto_fix, detour, navigate, dismiss.', required: true)]
        public AlertActionKind $kind,
        #[ApiProperty(description: 'Human-readable label for the action button.', required: true)]
        public string $label,
        #[ApiProperty(
            description: 'Machine-readable payload for the action. A `navigate` action carries `lat`/`lon` and, for the terrain rules, `segments`: the ordered geometry of the concerned road stretch as a list of `[lat, lon]` polylines, highlighted on the internal map (issue #982).',
            openapiContext: ['type' => 'object', 'additionalProperties' => true],
        )]
        public array $payload = [],
    ) {
    }

    /**
     * Wire format for the frontend, or null when the kind is not wired there yet
     * (issue #397): `auto_fix` and `detour` would render a dead disabled button,
     * so they are not delivered at all.
     *
     * @return array{kind: string, label: string, payload: array<string, mixed>}|null
     */
    public function toDeliverablePayload(): ?array
    {
        if (AlertActionKind::NAVIGATE !== $this->kind && AlertActionKind::DISMISS !== $this->kind) {
            return null;
        }

        return [
            'kind' => $this->kind->value,
            'label' => $this->label,
            'payload' => $this->payload,
        ];
    }
}
