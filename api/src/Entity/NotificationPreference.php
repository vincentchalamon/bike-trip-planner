<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-user opt-in for a server-pushed notification category (#1124).
 *
 * One row per (user, category). The absence of a row means "use the category
 * default" ({@see NotificationCategory::defaultEnabled()}), so a row is only
 * written when the user overrides that default.
 */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_user_category', columns: ['user_id', 'category'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(enumType: NotificationCategory::class)]
        private NotificationCategory $category,
        #[ORM\Column]
        private bool $enabled,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): NotificationCategory
    {
        return $this->category;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
