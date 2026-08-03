<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use App\Enum\AlertCode;
use App\Enum\AlertType;

readonly class Alert
{
    public function __construct(
        // No default on purpose: every emission site must state which rule variant it
        // stands for. Nullable only to hydrate alerts persisted before the code existed.
        #[ApiProperty(description: 'Stable identifier of the rule variant that raised this alert. Null on alerts persisted before the code was introduced (issue #876).')]
        public ?AlertCode $code,
        public AlertType $type,
        public string $message,
        public ?float $lat = null,
        public ?float $lon = null,
        #[ApiProperty(description: 'Optional contextual action for this alert.')]
        public ?AlertAction $action = null,
    ) {
    }
}
