<?php

declare(strict_types=1);

namespace App\Routing;

use App\ApiResource\Model\Coordinate;

final readonly class RoutingResult
{
    /**
     * @param list<Coordinate> $coordinates
     */
    public function __construct(
        public array $coordinates,
        public float $distance,
        public float $elevationGain,
        public float $duration,
    ) {
    }
}
