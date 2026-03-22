<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'stage')]
#[ORM\Index(name: 'idx_stage_trip_position', columns: ['trip_id', 'position'])]
class Stage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private int $dayNumber;

    #[ORM\Column]
    private float $distance;

    #[ORM\Column]
    private float $elevation;

    #[ORM\Column]
    private float $elevationLoss = 0.0;

    #[ORM\Column]
    private float $startLat;

    #[ORM\Column]
    private float $startLon;

    #[ORM\Column]
    private float $startEle = 0.0;

    #[ORM\Column]
    private float $endLat;

    #[ORM\Column]
    private float $endLon;

    #[ORM\Column]
    private float $endEle = 0.0;

    /** @var list<array{lat: float, lon: float, ele: float}> */
    #[ORM\Column(type: 'jsonb')]
    private array $geometry = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $isRestDay = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'jsonb', nullable: true)]
    private ?array $weather = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'jsonb')]
    private array $alerts = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'jsonb')]
    private array $pois = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'jsonb')]
    private array $accommodations = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'jsonb', nullable: true)]
    private ?array $selectedAccommodation = null;

    public function __construct(#[ORM\ManyToOne(targetEntity: Trip::class, inversedBy: 'stages')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Trip $trip, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrip(): Trip
    {
        return $this->trip;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getDayNumber(): int
    {
        return $this->dayNumber;
    }

    public function setDayNumber(int $dayNumber): self
    {
        $this->dayNumber = $dayNumber;

        return $this;
    }

    public function getDistance(): float
    {
        return $this->distance;
    }

    public function setDistance(float $distance): self
    {
        $this->distance = $distance;

        return $this;
    }

    public function getElevation(): float
    {
        return $this->elevation;
    }

    public function setElevation(float $elevation): self
    {
        $this->elevation = $elevation;

        return $this;
    }

    public function getElevationLoss(): float
    {
        return $this->elevationLoss;
    }

    public function setElevationLoss(float $elevationLoss): self
    {
        $this->elevationLoss = $elevationLoss;

        return $this;
    }

    public function getStartLat(): float
    {
        return $this->startLat;
    }

    public function setStartLat(float $startLat): self
    {
        $this->startLat = $startLat;

        return $this;
    }

    public function getStartLon(): float
    {
        return $this->startLon;
    }

    public function setStartLon(float $startLon): self
    {
        $this->startLon = $startLon;

        return $this;
    }

    public function getStartEle(): float
    {
        return $this->startEle;
    }

    public function setStartEle(float $startEle): self
    {
        $this->startEle = $startEle;

        return $this;
    }

    public function getEndLat(): float
    {
        return $this->endLat;
    }

    public function setEndLat(float $endLat): self
    {
        $this->endLat = $endLat;

        return $this;
    }

    public function getEndLon(): float
    {
        return $this->endLon;
    }

    public function setEndLon(float $endLon): self
    {
        $this->endLon = $endLon;

        return $this;
    }

    public function getEndEle(): float
    {
        return $this->endEle;
    }

    public function setEndEle(float $endEle): self
    {
        $this->endEle = $endEle;

        return $this;
    }

    /** @return list<array{lat: float, lon: float, ele: float}> */
    public function getGeometry(): array
    {
        return $this->geometry;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $geometry */
    public function setGeometry(array $geometry): self
    {
        $this->geometry = $geometry;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function isRestDay(): bool
    {
        return $this->isRestDay;
    }

    public function setIsRestDay(bool $isRestDay): self
    {
        $this->isRestDay = $isRestDay;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getWeather(): ?array
    {
        return $this->weather;
    }

    /** @param array<string, mixed>|null $weather */
    public function setWeather(?array $weather): self
    {
        $this->weather = $weather;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getAlerts(): array
    {
        return $this->alerts;
    }

    /** @param list<array<string, mixed>> $alerts */
    public function setAlerts(array $alerts): self
    {
        $this->alerts = $alerts;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getPois(): array
    {
        return $this->pois;
    }

    /** @param list<array<string, mixed>> $pois */
    public function setPois(array $pois): self
    {
        $this->pois = $pois;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getAccommodations(): array
    {
        return $this->accommodations;
    }

    /** @param list<array<string, mixed>> $accommodations */
    public function setAccommodations(array $accommodations): self
    {
        $this->accommodations = $accommodations;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSelectedAccommodation(): ?array
    {
        return $this->selectedAccommodation;
    }

    /** @param array<string, mixed>|null $selectedAccommodation */
    public function setSelectedAccommodation(?array $selectedAccommodation): self
    {
        $this->selectedAccommodation = $selectedAccommodation;

        return $this;
    }
}
