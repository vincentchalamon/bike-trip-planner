<?php

declare(strict_types=1);

namespace App\ApiResource;

final readonly class GeocodeResult
{
    public function __construct(
        public string $name,
        public float $lat,
        public float $lon,
        public string $displayName,
        public string $type = 'place',
    ) {
    }
}
