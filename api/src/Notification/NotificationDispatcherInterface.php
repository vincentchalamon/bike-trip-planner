<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;

interface NotificationDispatcherInterface
{
    /**
     * Dispatches a server push to a user for a category, unless the user has opted
     * out of that category. Returns whether the push was dispatched.
     *
     * @param array<string, string> $data
     */
    public function dispatch(string $userId, NotificationCategory $category, string $title, string $body, array $data = []): bool;
}
