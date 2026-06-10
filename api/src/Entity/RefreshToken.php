<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
#[ORM\UniqueConstraint(name: 'uniq_refresh_token_token', columns: ['token'])]
#[ORM\Index(name: 'idx_refresh_token_user', columns: ['user_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Successor token set when this one is rotated. Kept (rather than deleting
     * the row) so a reload race that re-sends the pre-rotation token resolves to
     * its successor within the grace window instead of being rejected (#649).
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $replacedByToken = null;

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

    public function getReplacedByToken(): ?string
    {
        return $this->replacedByToken;
    }

    /**
     * Rotates this token: point it at its successor and cut its life to the grace
     * window, so a reload race that re-sends it still resolves briefly (#649).
     */
    public function replaceWith(string $successorToken, \DateTimeImmutable $graceExpiresAt): void
    {
        $this->replacedByToken = $successorToken;
        $this->expiresAt = $graceExpiresAt;
    }

    public function isValid(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
