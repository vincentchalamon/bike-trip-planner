<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBikeShops;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class CheckBikeShopsHandler extends AbstractTripMessageHandler
{
    private const int MINIMUM_DAYS_FOR_CHECK = 5;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(CheckBikeShops $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        // BR-06: Skip if trip is 5 days or fewer
        if (\count($stages) <= self::MINIMUM_DAYS_FOR_CHECK) {
            $this->computationTracker->markDone($tripId, ComputationName::BIKE_SHOPS);

            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::BIKE_SHOPS, function () use ($tripId, $stages, $locale): void {
            $stagesWithoutBikeShop = [];

            foreach ($stages as $i => $stage) {
                $query = $this->queryBuilder->buildBikeShopQuery($stage->geometry ?: [$stage->startPoint, $stage->endPoint]);
                $result = $this->scanner->query($query);

                $bikeShops = $result['elements'] ?? [];

                if ([] === $bikeShops) {
                    $stagesWithoutBikeShop[] = [
                        'stageIndex' => $i,
                        'dayNumber' => $stage->dayNumber,
                        'type' => AlertType::NUDGE->value,
                        'message' => $this->translator->trans(
                            'alert.bike_shop.nudge',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::BIKE_SHOP_ALERTS, [
                'alerts' => $stagesWithoutBikeShop,
            ]);
        });
    }
}
