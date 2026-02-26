<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final readonly class PointOfInterest
{
    public function __construct(
        public string $name,
        public string $category,
        public float $lat,
        public float $lon,
        public ?float $distanceFromStart = null,
    ) {
    }
}
