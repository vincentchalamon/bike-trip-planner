<?php

declare(strict_types=1);

namespace App\ApiResource;

final class StageSelectAccommodationRequest
{
    /**
     * Latitude of the accommodation to select.
     * Pass null to deselect the currently selected accommodation.
     */
    public ?float $selectedAccommodationLat = null;

    /**
     * Longitude of the accommodation to select.
     * Pass null to deselect the currently selected accommodation.
     */
    public ?float $selectedAccommodationLon = null;
}
