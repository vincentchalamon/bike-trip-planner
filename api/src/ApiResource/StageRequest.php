<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\Validator\Constraints as Assert;

final class StageRequest
{
    #[Assert\PositiveOrZero]
    public ?int $position = null;

    #[Assert\NotNull(groups: ['stage_create'])]
    public ?Coordinate $startPoint = null;

    #[Assert\NotNull(groups: ['stage_create'])]
    public ?Coordinate $endPoint = null;

    public ?string $label = null;

    #[Assert\Positive]
    public ?float $distance = null;

    #[Assert\PositiveOrZero]
    public ?int $toIndex = null;
}
