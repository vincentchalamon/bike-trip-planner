<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CheckCulturalPois
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
