<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class StageSelectAccommodationRequest
{
    /**
     * Latitude of the accommodation to select.
     * Pass null to deselect the currently selected accommodation.
     */
    #[Assert\Range(min: -90, max: 90)]
    public ?float $selectedAccommodationLat = null;

    /**
     * Longitude of the accommodation to select.
     * Pass null to deselect the currently selected accommodation.
     */
    #[Assert\Range(min: -180, max: 180)]
    public ?float $selectedAccommodationLon = null;
}
