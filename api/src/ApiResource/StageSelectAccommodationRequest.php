<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class StageSelectAccommodationRequest
{
    /**
     * Index of the accommodation to select within the stage's accommodations list.
     * Pass null to deselect the currently selected accommodation.
     */
    #[Assert\PositiveOrZero]
    public ?int $selectedAccommodationIndex = null;
}
