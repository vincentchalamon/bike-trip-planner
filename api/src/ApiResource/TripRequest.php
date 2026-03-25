<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Stage;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO and persistent entity for {@see Trip}.
 *
 * Serves dual purpose: API Platform input (validation constraints) and Doctrine entity (ORM mapping).
 * Persistence-only fields (id, title, sourceType, locale, timestamps, stages) are excluded from
 * the API schema via #[ApiProperty(writable: false, readable: false)].
 */
#[ORM\Entity]
#[ORM\Table(name: 'trip')]
#[ORM\HasLifecycleCallbacks]
final class TripRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ApiProperty(readable: false, writable: false)]
    public ?Uuid $id = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\NotBlank(groups: ['trip_request:create'])]
    #[Assert\Url(protocols: ['https'])]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $startDate = null {
        set(?\DateTimeImmutable $value) {
            $this->startDate = $value instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'))
                : null;
        }
    }

    // Number of days: endDate - startDate + 1
    // If endDate omitted, default from distance (ceil(distance/80))
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'End date must be after start date.')]
    public ?\DateTimeImmutable $endDate = null {
        set(?\DateTimeImmutable $value) {
            $this->endDate = $value instanceof \DateTimeImmutable
                ? new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'))
                : null;
        }
    }

    // Fatigue factor (0.9 = -10%/day), configurable by the user
    #[ORM\Column]
    #[Assert\Range(min: 0.5, max: 1.0)]
    public float $fatigueFactor = 0.9;

    // Elevation penalty (50 = -1km par 50m D+), configurable by the user
    #[ORM\Column]
    #[Assert\Positive]
    public float $elevationPenalty = 50.0;

    #[ORM\Column]
    public bool $ebikeMode = false;

    #[ORM\Column]
    #[ApiProperty(description: 'Typical departure hour (0-23, default 8)')]
    #[Assert\Range(min: 0, max: 23)]
    public int $departureHour = 8;

    // Maximum distance per day cap (km), applied after pacing formula
    #[ORM\Column]
    #[ApiProperty(description: 'Maximum distance cap per day in km (default: 80)')]
    #[Assert\Range(min: 30, max: 300)]
    public float $maxDistancePerDay = 80.0;

    // Average cycling speed (km/h), reserved for travel time estimation (Sprint 5, issue #61)
    #[ORM\Column]
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
    #[ORM\Column(type: 'text[]')]
    #[ApiProperty(description: 'Enabled OSM tourism types for accommodation search (default: all 7 types)')]
    #[Assert\Count(min: 1, minMessage: 'At least one accommodation type must be enabled.')]
    #[Assert\All([
        new Assert\Choice(choices: self::ALL_ACCOMMODATION_TYPES),
    ])]
    public array $enabledAccommodationTypes = self::ALL_ACCOMMODATION_TYPES;

    // --- Persistence-only fields (not exposed in API input/output) ---

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[ApiProperty(readable: false)]
    public ?string $title = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    public ?string $sourceType = null;

    #[ORM\Column(length: 5)]
    #[ApiProperty(readable: false, writable: false)]
    public string $locale = 'en';

    #[ORM\Column]
    #[ApiProperty(readable: false, writable: false)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[ApiProperty(readable: false, writable: false)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'trips')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[ApiProperty(readable: false, writable: false)]
    public ?User $user = null;

    /** @var Collection<int, Stage> */
    #[ORM\OneToMany(targetEntity: Stage::class, mappedBy: 'trip', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ApiProperty(readable: false, writable: false)]
    public Collection $stages;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->stages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addStage(Stage $stage): void
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
        }
    }

    public function clearStages(): void
    {
        $this->stages->clear();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
