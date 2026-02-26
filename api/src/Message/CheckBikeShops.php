<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CheckBikeShops
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
