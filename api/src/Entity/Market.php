<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MarketRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MarketRepository::class)]
#[ORM\Table(name: 'market')]
#[ORM\UniqueConstraint(name: 'uniq_market_external_id', columns: ['external_id'])]
#[ORM\Index(name: 'idx_market_day_of_week', columns: ['day_of_week'])]
class Market
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private float $lat;

    #[ORM\Column]
    private float $lon;

    #[ORM\Column]
    private int $dayOfWeek;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $startTime = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $endTime = null;

    #[ORM\Column(length: 255)]
    private string $commune;

    #[ORM\Column(length: 255)]
    private string $department;

    #[ORM\Column(length: 50)]
    private string $source = 'data.gouv.fr';

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $externalId,
        #[ORM\Column(length: 255)]
        private string $name,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function setLat(float $lat): self
    {
        $this->lat = $lat;

        return $this;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function setLon(float $lon): self
    {
        $this->lon = $lon;

        return $this;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): ?string
    {
        return $this->startTime;
    }

    public function setStartTime(?string $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?string
    {
        return $this->endTime;
    }

    public function setEndTime(?string $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getCommune(): string
    {
        return $this->commune;
    }

    public function setCommune(string $commune): self
    {
        $this->commune = $commune;

        return $this;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function setDepartment(string $department): self
    {
        $this->department = $department;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): self
    {
        $this->importedAt = $importedAt;

        return $this;
    }
}
