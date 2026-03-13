<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ScanAccommodations
{
    public function __construct(
        public string $tripId,
        public int $radiusMeters = 5000,
    ) {
    }
}
