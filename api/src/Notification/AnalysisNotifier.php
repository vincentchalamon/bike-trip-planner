<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;
use App\Mercure\MercureSubscriptionCheckerInterface;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pushes the `analysisDone` notification once a trip's enrichment pipeline settles
 * (#1124), but only when the rider is NOT already watching the trip in real time.
 *
 * The guard is the core of the issue: if a Mercure SSE subscriber is live on the
 * trip topic, the frontend already receives the terminal `TRIP_READY` event, so a
 * push would be redundant noise. Anonymous trips (no owner) are never pushed. The
 * copy is localised to the trip's locale (falling back to English).
 */
final readonly class AnalysisNotifier
{
    public function __construct(
        private TripRequestRepositoryInterface $tripRequestRepository,
        private MercureSubscriptionCheckerInterface $subscriptionChecker,
        private NotificationDispatcherInterface $dispatcher,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $statuses per-computation terminal status map
     */
    public function notify(string $tripId, array $statuses): void
    {
        $ownerId = $this->tripRequestRepository->getOwnerId($tripId);
        if (null === $ownerId) {
            return;
        }

        // Rider is watching the SSE stream: they already got TRIP_READY, so do not
        // duplicate it as a push.
        if ($this->subscriptionChecker->hasActiveSubscriber($tripId)) {
            return;
        }

        $locale = $this->tripRequestRepository->getLocale($tripId) ?? 'en';
        $key = \in_array('failed', $statuses, true) ? 'failed' : 'done';

        $this->dispatcher->dispatch(
            $ownerId,
            NotificationCategory::ANALYSIS_DONE,
            $this->translator->trans(\sprintf('notification.analysis.%s.title', $key), [], 'notifications', $locale),
            $this->translator->trans(\sprintf('notification.analysis.%s.body', $key), [], 'notifications', $locale),
            ['tripId' => $tripId],
        );
    }
}
