<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for triggering an accommodation re-scan with a custom radius.
 */
final class AccommodationScanRequest
{
    public const int DEFAULT_ACCOMMODATION_RADIUS_METERS = 5000;

    public const int MAX_ACCOMMODATION_RADIUS_METERS = 15000;

    public const int MAX_ACCOMMODATION_RADIUS_KM = self::MAX_ACCOMMODATION_RADIUS_METERS / 1000;

    public const int DEFAULT_ACCOMMODATION_RADIUS_KM = self::DEFAULT_ACCOMMODATION_RADIUS_METERS / 1000;

    #[ApiProperty(description: 'Search radius in km (default: 5, max: 15, step: 2)')]
    #[Assert\Range(min: 1, max: self::MAX_ACCOMMODATION_RADIUS_KM)]
    public int $radiusKm = self::DEFAULT_ACCOMMODATION_RADIUS_KM;

    #[ApiProperty(description: 'Optional stage index to restrict the scan to a single stage')]
    #[Assert\PositiveOrZero]
    public ?int $stageIndex = null;
}
