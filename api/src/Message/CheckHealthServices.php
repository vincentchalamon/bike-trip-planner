<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\ComputationName;

final readonly class CheckHealthServices implements TracksComputation
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }

    #[\Override]
    public static function computation(): ComputationName
    {
        return ComputationName::HEALTH_SERVICES;
    }
}
