<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated geographic position (WGS84) carried by in-ride chat requests.
 *
 * Latitude is constrained to [-90, 90] and longitude to [-180, 180] per the
 * WGS84 convention. The bearing (heading), when provided, is expressed in
 * degrees clockwise from the geographic north, in the half-open range [0, 360).
 */
final class GeoPosition
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: -90, max: 90)]
        #[ApiProperty(description: 'Latitude in decimal degrees (WGS84).')]
        public float $lat,
        #[Assert\NotNull]
        #[Assert\Range(min: -180, max: 180)]
        #[ApiProperty(description: 'Longitude in decimal degrees (WGS84).')]
        public float $lon,
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Bearing must be in [0, 360).')]
        #[Assert\LessThan(value: 360, message: 'Bearing must be in [0, 360).')]
        #[ApiProperty(description: 'Optional bearing (heading) in degrees clockwise from north.')]
        public ?float $bearing = null,
    ) {
    }
}
