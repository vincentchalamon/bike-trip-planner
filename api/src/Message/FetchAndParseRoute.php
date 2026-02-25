<?php

declare(strict_types=1);

namespace App\Message;

final readonly class FetchAndParseRoute
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
