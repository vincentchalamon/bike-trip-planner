<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DevicePlatform;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A push-notification device token registered by a user (epic #1051).
 *
 * One row per physical FCM token. The token is globally unique: a device can only
 * be bound to a single account at a time, so re-registering a token already held
 * by another user reassigns it (see DeviceTokenRegisterProcessor).
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_token')]
#[ORM\UniqueConstraint(name: 'uniq_device_token_token', columns: ['token'])]
#[ORM\Index(name: 'idx_device_token_user', columns: ['user_id'])]
class DeviceToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 255)]
        private string $token,
        #[ORM\Column(enumType: DevicePlatform::class)]
        private DevicePlatform $platform,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlatform(): DevicePlatform
    {
        return $this->platform;
    }

    public function setPlatform(DevicePlatform $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
