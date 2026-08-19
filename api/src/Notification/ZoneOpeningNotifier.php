<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepositoryInterface;

/**
 * Pushes the `zoneOpening` announcement when a new reference zone is opened (#1124).
 *
 * This category is opt-in (OFF by default), so only users who explicitly enabled
 * it are targeted — resolved from the preference store, not from a broadcast. Zone
 * opening happens in the separate provisioner process ({@see make provision}), so
 * there is no event to hook: ops triggers the push with a console command
 * ({@see \App\Command\NotifyZoneOpenedCommand}) once a zone is promoted.
 */
final readonly class ZoneOpeningNotifier
{
    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private NotificationDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return int the number of pushes dispatched
     */
    public function notify(string $zoneSlug, string $zoneName): int
    {
        $dispatched = 0;

        foreach ($this->preferences->findUserIdsEnabled(NotificationCategory::ZONE_OPENING) as $userId) {
            $dispatched += $this->dispatcher->dispatch(
                $userId,
                NotificationCategory::ZONE_OPENING,
                'Nouvelle zone disponible',
                \sprintf('La zone %s est maintenant couverte. Planifiez-y votre prochain voyage !', $zoneName),
                ['zoneSlug' => $zoneSlug],
            ) ? 1 : 0;
        }

        return $dispatched;
    }
}
