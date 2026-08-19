<?php

declare(strict_types=1);

namespace App\Notification;

use App\Enum\NotificationCategory;
use App\Mercure\MercureSubscriptionCheckerInterface;
use App\Repository\TripRequestRepositoryInterface;

/**
 * Pushes the `analysisDone` notification once a trip's enrichment pipeline settles
 * (#1124), but only when the rider is NOT already watching the trip in real time.
 *
 * The guard is the core of the issue: if a Mercure SSE subscriber is live on the
 * trip topic, the frontend already receives the terminal `TRIP_READY` event, so a
 * push would be redundant noise. Anonymous trips (no owner) are never pushed.
 */
final readonly class AnalysisNotifier
{
    public function __construct(
        private TripRequestRepositoryInterface $tripRequestRepository,
        private MercureSubscriptionCheckerInterface $subscriptionChecker,
        private NotificationDispatcherInterface $dispatcher,
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

        $failed = \in_array('failed', $statuses, true);

        $this->dispatcher->dispatch(
            $ownerId,
            NotificationCategory::ANALYSIS_DONE,
            $failed ? 'Analyse incomplète' : 'Analyse terminée',
            $failed
                ? "Certaines étapes de votre voyage n'ont pas pu être analysées. Ouvrez l'app pour vérifier."
                : "Votre voyage est prêt. Ouvrez l'app pour découvrir les étapes et les alertes.",
            ['tripId' => $tripId],
        );
    }
}
