<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class Coordinate
{
    public function __construct(
        #[Assert\Range(min: -90, max: 90)]
        public float $lat,
        #[Assert\Range(min: -180, max: 180)]
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
