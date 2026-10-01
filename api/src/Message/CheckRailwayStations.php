<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\ComputationName;

final readonly class CheckRailwayStations implements TracksComputation
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }

    #[\Override]
    public static function computation(): ComputationName
    {
        return ComputationName::RAILWAY_STATIONS;
    }
}
