<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
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

    #[ApiProperty(description: 'Typical departure hour (0-23, default 8)')]
    #[Assert\Range(min: 0, max: 23)]
    public int $departureHour = 8;

    // Maximum distance per day cap (km), applied after pacing formula
    #[ApiProperty(description: 'Maximum distance cap per day in km (default: 80)')]
    #[Assert\Range(min: 30, max: 300)]
    public float $maxDistancePerDay = 80.0;

    // Average cycling speed (km/h), reserved for travel time estimation (Sprint 5, issue #61)
    #[ApiProperty(description: 'Average cycling speed in km/h (default: 15)')]
    #[Assert\Range(min: 5, max: 50)]
    public float $averageSpeed = 15.0;

    /** @var list<string> Single source of truth for all supported OSM tourism types. */
    public const array ALL_ACCOMMODATION_TYPES = ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel'];

    /**
     * Enabled accommodation types for Overpass filtering.
     * All 7 OSM tourism types are enabled by default.
     * At least one type must remain enabled.
     *
     * @var list<string>
     */
    #[ApiProperty(description: 'Enabled OSM tourism types for accommodation search (default: all 7 types)')]
    #[Assert\Count(min: 1, minMessage: 'At least one accommodation type must be enabled.')]
    #[Assert\All([
        new Assert\Choice(choices: self::ALL_ACCOMMODATION_TYPES),
    ])]
    public array $enabledAccommodationTypes = self::ALL_ACCOMMODATION_TYPES;
}
