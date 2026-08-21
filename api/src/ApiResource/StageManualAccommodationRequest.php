<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for adding a manually-entered ("hors-app") accommodation to a stage.
 *
 * The address is geocoded backend-side (Nominatim) into the coordinates the
 * produced accommodation carries; a total price, when given, maps onto the
 * standard exact-price contract (min = max = price, isExactPrice = true).
 */
final class StageManualAccommodationRequest
{
    #[ApiProperty(description: 'Accommodation title (required).')]
    #[Assert\NotBlank]
    public string $name = '';

    #[ApiProperty(description: 'Postal address (required); geocoded backend-side into coordinates.')]
    #[Assert\NotBlank]
    public string $address = '';

    #[ApiProperty(description: 'Optional total price in euros. Maps to estimatedPriceMin = estimatedPriceMax with isExactPrice = true.')]
    #[Assert\PositiveOrZero]
    public ?float $priceTotal = null;

    #[ApiProperty(description: 'Optional booking/listing link.')]
    public ?string $url = null;
}
