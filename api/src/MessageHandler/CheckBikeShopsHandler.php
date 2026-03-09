<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
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

    private const float BIKE_SHOP_PROXIMITY_METERS = 2000.0;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeoDistanceInterface $haversine,
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
            // Single Overpass query using decimated route points (shared cache key with ScanAllOsmDataHandler)
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $query = $this->queryBuilder->buildBikeShopQuery($points);
            $result = $this->scanner->query($query);

            /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse bike shop locations
            $bikeShopLocations = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $bikeShopLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }

            // Check each stage for nearby bike shops
            $stagesWithoutBikeShop = [];
            foreach ($stages as $i => $stage) {
                $hasNearby = false;
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $midpoint = $geometry[(int) (\count($geometry) / 2)];

                foreach ($bikeShopLocations as $shop) {
                    if ($this->haversine->inMeters($midpoint->lat, $midpoint->lon, $shop['lat'], $shop['lon']) < self::BIKE_SHOP_PROXIMITY_METERS) {
                        $hasNearby = true;
                        break;
                    }
                }

                if (!$hasNearby) {
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
