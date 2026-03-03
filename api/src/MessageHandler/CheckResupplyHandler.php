<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckResupply;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class CheckResupplyHandler extends AbstractTripMessageHandler
{
    private const float MAX_GAP_KM = 50.0;

    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(CheckResupply $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::RESUPPLY, function () use ($tripId, $stages, $locale): void {
            $nudges = [];
            $distanceSinceLastResupply = 0.0;

            foreach ($stages as $i => $stage) {
                $hasResupply = false;

                foreach ($stage->pois as $poi) {
                    if (\in_array($poi->category, self::RESUPPLY_CATEGORIES, true)) {
                        $hasResupply = true;
                        break;
                    }
                }

                if ($hasResupply) {
                    $distanceSinceLastResupply = 0.0;
                } else {
                    $distanceSinceLastResupply += $stage->distance;

                    if ($distanceSinceLastResupply > self::MAX_GAP_KM) {
                        $nudges[] = [
                            'stageIndex' => $i,
                            'distance' => round($distanceSinceLastResupply, 1),
                            'message' => $this->translator->trans(
                                'alert.resupply.nudge',
                                [
                                    '%distance%' => \sprintf('%.0f', $distanceSinceLastResupply),
                                    '%stage%' => $stage->dayNumber,
                                ],
                                'alerts',
                                $locale,
                            ),
                        ];
                        $distanceSinceLastResupply = 0.0; // Reset after nudge
                    }
                }
            }

            $this->publisher->publish($tripId, MercureEventType::RESUPPLY_NUDGES, [
                'nudges' => $nudges,
            ]);
        });
    }
}
