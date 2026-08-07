<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated geographic position (WGS84) carried by a nearby-POI search request.
 *
 * Latitude is constrained to [-90, 90] and longitude to [-180, 180] per the
 * WGS84 convention.
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
    ) {
    }
}
