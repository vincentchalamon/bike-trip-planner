<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for {@see Trip}.
 */
final class TripRequest
{
    #[Assert\NotBlank(groups: ['trip_request:create'])]
    #[Assert\Url(protocols: ['https'])]
    public ?string $sourceUrl = null;

    public ?\DateTimeImmutable $startDate = null {
        set(?\DateTimeImmutable $value) {
            $this->startDate = $value instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'))
                : null;
        }
    }

    // Number of days: endDate - startDate + 1
    // If endDate omitted, default from distance (ceil(distance/80))
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'End date must be after start date.')]
    public ?\DateTimeImmutable $endDate = null {
        set(?\DateTimeImmutable $value) {
            $this->endDate = $value instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'))
                : null;
        }
    }

    // Fatigue factor (0.9 = -10%/day), configurable by the user
    #[Assert\Range(min: 0.5, max: 1.0)]
    public float $fatigueFactor = 0.9;

    // Elevation penalty (50 = -1km par 50m D+), configurable by the user
    #[Assert\Positive]
    public float $elevationPenalty = 50.0;

    public bool $ebikeMode = false;
}
