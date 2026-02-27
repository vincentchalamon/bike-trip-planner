<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final readonly class Accommodation
{
    public function __construct(
        public string $name,
        public string $type,
        public float $lat,
        public float $lon,
        public float $estimatedPriceMin,
        public float $estimatedPriceMax,
        public bool $isExactPrice,
        public ?string $url = null,
    ) {
    }
}
