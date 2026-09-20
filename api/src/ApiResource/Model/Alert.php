<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertType;

readonly class Alert
{
    public function __construct(
        // No default on purpose: every emission site must state which rule variant it
        // stands for. Nullable only to hydrate alerts persisted before the code existed.
        #[ApiProperty(description: 'Stable identifier of the rule variant that raised this alert. Null on alerts persisted before the code was introduced (issue #876).')]
        public ?AlertCode $code,
        public AlertType $type,
        // Not the prose: the catalogue key and its arguments, rendered at read time in the
        // reader's language (ADR-069). One code can pick either of two keys — a cultural POI
        // with or without a name — so the key is carried, not derived from the code.
        #[ApiProperty(description: 'Translation key of the alert message, in the `alerts` catalogue.')]
        public string $messageKey,
        /** @var array<string, string|int|float|list<string>> */
        #[ApiProperty(
            description: 'Arguments of the message, keyed by placeholder (`%elevation%`), raw and unformatted: a distance is metres, a gradient a percentage. The placeholders naming a stage (`%stage%`, `%from%`, `%to%`) are absent — they are derived from the owning stage at read time, never stored (ADR-068).',
            openapiContext: ['type' => 'object', 'additionalProperties' => true],
        )]
        public array $parameters = [],
        /** @var array<string, string> */
        #[ApiProperty(
            description: 'How to render each raw parameter, keyed by the same placeholder. A placeholder absent from this map is rendered as-is.',
            openapiContext: ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => AlertParameterFormat::VALUES]],
        )]
        public array $parameterFormats = [],
        public ?float $lat = null,
        public ?float $lon = null,
        #[ApiProperty(description: 'Optional contextual action for this alert.')]
        public ?AlertAction $action = null,
    ) {
    }
}
