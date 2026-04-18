<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessRequestStatus;
use App\Repository\AccessRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessRequestRepository::class)]
#[ORM\Table(name: 'access_request')]
#[ORM\UniqueConstraint(name: 'uniq_access_request_email', columns: ['email'])]
#[ORM\Index(name: 'idx_access_request_status', columns: ['status'])]
class AccessRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 32, enumType: AccessRequestStatus::class)]
    private AccessRequestStatus $status = AccessRequestStatus::PENDING_VERIFICATION;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param non-empty-string $email */
    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(length: 45)]
        private string $ip,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getEmail(): string
    {
        return $this->email;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getStatus(): AccessRequestStatus
    {
        return $this->status;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function verify(): self
    {
        $this->status = AccessRequestStatus::VERIFIED;
        $this->verifiedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isVerified(): bool
    {
        return AccessRequestStatus::VERIFIED === $this->status;
    }
}
