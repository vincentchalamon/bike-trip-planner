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

    /**
     * The two-key shape the spatial queries and the geometry payloads take.
     *
     * @return array{lat: float, lon: float}
     */
    public function toLatLon(): array
    {
        return ['lat' => $this->lat, 'lon' => $this->lon];
    }
}
