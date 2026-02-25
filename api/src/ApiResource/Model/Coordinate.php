<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final readonly class Coordinate
{
    public function __construct(
        public float $lat,
        public float $lon,
        public float $ele = 0.0,
    ) {
    }
}
