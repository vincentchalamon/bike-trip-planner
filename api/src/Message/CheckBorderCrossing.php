<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\ComputationName;

final readonly class CheckBorderCrossing implements TracksComputation
{
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }

    #[\Override]
    public static function computation(): ComputationName
    {
        return ComputationName::BORDER_CROSSING;
    }
}
