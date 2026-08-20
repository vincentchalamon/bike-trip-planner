<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pushes the `zoneOpening` announcement when a new reference zone is opened (#1124).
 *
 * This category is opt-in (OFF by default), so only users who explicitly enabled
 * it are targeted — resolved from the preference store, not from a broadcast. Zone
 * opening happens in the separate provisioner process ({@see make provision}), so
 * there is no event to hook: ops triggers the push with a console command
 * ({@see \App\Command\NotifyZoneOpenedCommand}) once a zone is promoted. Copy is
 * localised to each targeted user's locale (falling back to English).
 */
final readonly class ZoneOpeningNotifier
{
    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private NotificationDispatcherInterface $dispatcher,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return int the number of pushes dispatched
     */
    public function notify(string $zoneSlug, string $zoneName): int
    {
        $dispatched = 0;

        foreach ($this->preferences->findEnabledUsers(NotificationCategory::ZONE_OPENING) as $user) {
            $locale = '' !== $user['locale'] ? $user['locale'] : 'en';

            $dispatched += $this->dispatcher->dispatch(
                $user['id'],
                NotificationCategory::ZONE_OPENING,
                $this->translator->trans('notification.zone_opening.title', [], 'notifications', $locale),
                $this->translator->trans('notification.zone_opening.body', ['%zone%' => $zoneName], 'notifications', $locale),
                ['zoneSlug' => $zoneSlug],
            ) ? 1 : 0;
        }

        return $dispatched;
    }
}
