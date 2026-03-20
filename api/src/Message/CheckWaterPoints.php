<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CheckWaterPoints
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }
}
