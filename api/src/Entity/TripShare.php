<?php

declare(strict_types=1);

namespace App\Entity;

use App\ApiResource\TripRequest;
use App\Repository\TripShareRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TripShareRepository::class)]
#[ORM\Table(name: 'trip_share')]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_token', columns: ['token'])]
#[ORM\Index(name: 'idx_trip_share_trip', columns: ['trip_id'])]
class TripShare
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TripRequest::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TripRequest $trip,
        #[ORM\Column(length: 64)]
        private string $token,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrip(): TripRequest
    {
        return $this->trip;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isValid(): bool
    {
        return !$this->expiresAt instanceof \DateTimeImmutable || $this->expiresAt > new \DateTimeImmutable();
    }
}
