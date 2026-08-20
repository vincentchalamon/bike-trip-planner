<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;
use App\Message\SendPushNotification;
use App\Repository\NotificationPreferenceRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Single opt-in gate for every server-pushed category (#1124).
 *
 * Checks the user's per-category preference and, when enabled, emits a
 * {@see SendPushNotification} carrying the category. The push handler resolves the
 * user's device tokens and no-ops when there is none, so a user with a disabled
 * category or no registered device is never reached (RGPD).
 */
final readonly class NotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private NotificationPreferenceRepositoryInterface $preferences,
    ) {
    }

    public function dispatch(string $userId, NotificationCategory $category, string $title, string $body, array $data = []): bool
    {
        if (!$this->preferences->isEnabled($userId, $category)) {
            return false;
        }

        $this->messageBus->dispatch(new SendPushNotification(
            title: $title,
            body: $body,
            userId: $userId,
            data: $data,
            category: $category->value,
        ));

        return true;
    }
}
