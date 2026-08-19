<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;

interface NotificationPreferenceRepositoryInterface
{
    /**
     * Effective opt-in for a category: the stored value, or the category default
     * when the user has no explicit row.
     */
    public function isEnabled(string $userId, NotificationCategory $category): bool;

    /**
     * User ids that explicitly enabled a category (used for opt-in categories such
     * as zone opening, where the default is OFF so only opted-in users are targeted).
     *
     * @return list<string>
     */
    public function findUserIdsEnabled(NotificationCategory $category): array;

    public function findOne(User $user, NotificationCategory $category): ?NotificationPreference;

    public function save(NotificationPreference $preference): void;
}
