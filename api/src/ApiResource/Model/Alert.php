<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use App\Enum\AlertType;

readonly class Alert
{
    public function __construct(
        public AlertType $type,
        public string $message,
        public ?float $lat = null,
        public ?float $lon = null,
    ) {
    }
}
