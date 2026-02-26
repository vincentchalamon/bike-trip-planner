<?php

declare(strict_types=1);

namespace App\Message;

final readonly class AnalyzeWind
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
