<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MagicLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MagicLinkRepository::class)]
#[ORM\Table(name: 'magic_link')]
#[ORM\Index(name: 'idx_magic_link_token', columns: ['token'])]
#[ORM\Index(name: 'idx_magic_link_user_expires', columns: ['user_id', 'expires_at'])]
class MagicLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 128)]
        private string $token,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
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

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function consume(): self
    {
        $this->consumedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isValid(): bool
    {
        return !$this->consumedAt instanceof \DateTimeImmutable && $this->expiresAt > new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
