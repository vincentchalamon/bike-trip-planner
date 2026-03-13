<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Scanner\QueryBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for triggering an accommodation re-scan with a custom radius.
 */
final class AccommodationScanRequest
{
    #[ApiProperty(description: 'Search radius in km (default: 5, max: 15, step: 2)')]
    #[Assert\Range(min: 1, max: QueryBuilderInterface::MAX_ACCOMMODATION_RADIUS_KM)]
    public int $radiusKm = QueryBuilderInterface::DEFAULT_ACCOMMODATION_RADIUS_KM;

    #[ApiProperty(description: 'Optional stage index to restrict the scan to a single stage')]
    #[Assert\PositiveOrZero]
    public ?int $stageIndex = null;
}
