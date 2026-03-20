<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ScanPois
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }
}
