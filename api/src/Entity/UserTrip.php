<?php

declare(strict_types=1);

namespace App\Entity;

use App\ApiResource\TripRequest;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'user_trip')]
#[ORM\UniqueConstraint(name: 'uniq_user_trip', columns: ['user_id', 'trip_id'])]
#[ORM\Index(name: 'idx_user_trip_user', columns: ['user_id'])]
class UserTrip
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userTrips')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: TripRequest::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TripRequest $trip,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrip(): TripRequest
    {
        return $this->trip;
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

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
