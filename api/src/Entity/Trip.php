<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'trip')]
#[ORM\HasLifecycleCallbacks]
class Trip
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column]
    private float $fatigueFactor = 0.9;

    #[ORM\Column]
    private float $elevationPenalty = 50.0;

    #[ORM\Column]
    private bool $ebikeMode = false;

    #[ORM\Column]
    private int $departureHour = 8;

    #[ORM\Column]
    private float $maxDistancePerDay = 80.0;

    #[ORM\Column]
    private float $averageSpeed = 15.0;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $enabledAccommodationTypes = ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel'];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Stage> */
    #[ORM\OneToMany(targetEntity: Stage::class, mappedBy: 'trip', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stages;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->stages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getFatigueFactor(): float
    {
        return $this->fatigueFactor;
    }

    public function setFatigueFactor(float $fatigueFactor): self
    {
        $this->fatigueFactor = $fatigueFactor;

        return $this;
    }

    public function getElevationPenalty(): float
    {
        return $this->elevationPenalty;
    }

    public function setElevationPenalty(float $elevationPenalty): self
    {
        $this->elevationPenalty = $elevationPenalty;

        return $this;
    }

    public function isEbikeMode(): bool
    {
        return $this->ebikeMode;
    }

    public function setEbikeMode(bool $ebikeMode): self
    {
        $this->ebikeMode = $ebikeMode;

        return $this;
    }

    public function getDepartureHour(): int
    {
        return $this->departureHour;
    }

    public function setDepartureHour(int $departureHour): self
    {
        $this->departureHour = $departureHour;

        return $this;
    }

    public function getMaxDistancePerDay(): float
    {
        return $this->maxDistancePerDay;
    }

    public function setMaxDistancePerDay(float $maxDistancePerDay): self
    {
        $this->maxDistancePerDay = $maxDistancePerDay;

        return $this;
    }

    public function getAverageSpeed(): float
    {
        return $this->averageSpeed;
    }

    public function setAverageSpeed(float $averageSpeed): self
    {
        $this->averageSpeed = $averageSpeed;

        return $this;
    }

    /** @return list<string> */
    public function getEnabledAccommodationTypes(): array
    {
        return $this->enabledAccommodationTypes;
    }

    /** @param list<string> $enabledAccommodationTypes */
    public function setEnabledAccommodationTypes(array $enabledAccommodationTypes): self
    {
        $this->enabledAccommodationTypes = $enabledAccommodationTypes;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Stage> */
    public function getStages(): Collection
    {
        return $this->stages;
    }

    public function addStage(Stage $stage): self
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
        }

        return $this;
    }

    public function removeStage(Stage $stage): self
    {
        $this->stages->removeElement($stage);

        return $this;
    }

    public function clearStages(): self
    {
        $this->stages->clear();

        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
