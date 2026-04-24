<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBikeShops;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
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
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeoDistanceInterface $haversine,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager);
    }

    public function __invoke(CheckBikeShops $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
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

            /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}, tags?: array<string, string>}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse bike shop locations, distinguishing repair shops from sale-only shops
            $repairShopLocations = [];
            $saleOnlyShopLocations = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $tags = $element['tags'] ?? [];
                $hasRepair = isset($tags['service:bicycle:repair']) && 'yes' === $tags['service:bicycle:repair'];

                if ($hasRepair) {
                    $repairShopLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
                } else {
                    $saleOnlyShopLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
                }
            }

            // Check each stage for nearby bike shops
            $stagesWithoutBikeShop = [];
            foreach ($stages as $i => $stage) {
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $midpoint = $geometry[(int) (\count($geometry) / 2)];

                $hasNearbyRepair = false;
                foreach ($repairShopLocations as $shop) {
                    if ($this->haversine->inMeters($midpoint->lat, $midpoint->lon, $shop['lat'], $shop['lon']) < self::BIKE_SHOP_PROXIMITY_METERS) {
                        $hasNearbyRepair = true;
                        break;
                    }
                }

                if ($hasNearbyRepair) {
                    continue;
                }

                $hasNearbySaleOnly = array_any($saleOnlyShopLocations, fn (array $shop): bool => $this->haversine->inMeters($midpoint->lat, $midpoint->lon, $shop['lat'], $shop['lon']) < self::BIKE_SHOP_PROXIMITY_METERS);

                $translationKey = $hasNearbySaleOnly ? 'alert.bike_shop.no_repair_nudge' : 'alert.bike_shop.nudge';
                $allShops = [...$repairShopLocations, ...$saleOnlyShopLocations];
                $nearestShop = $this->findNearestShop($midpoint, $allShops);
                $stagesWithoutBikeShop[] = [
                    'stageIndex' => $i,
                    'dayNumber' => $stage->dayNumber,
                    'type' => AlertType::NUDGE->value,
                    'message' => $this->translator->trans(
                        $translationKey,
                        ['%stage%' => $stage->dayNumber],
                        'alerts',
                        $locale,
                    ),
                    'action' => null !== $nearestShop ? [
                        'kind' => AlertActionKind::NAVIGATE->value,
                        'label' => $this->translator->trans('alert.bike_shop.action', [], 'alerts', $locale),
                        'payload' => ['lat' => $nearestShop['lat'], 'lon' => $nearestShop['lon']],
                    ] : null,
                ];
            }

            $this->publisher->publish($tripId, MercureEventType::BIKE_SHOP_ALERTS, [
                'alerts' => $stagesWithoutBikeShop,
            ]);
        }, $generation);
    }

    /**
     * Finds the nearest bike shop to a given midpoint.
     *
     * @param list<array{lat: float, lon: float}> $shops
     *
     * @return array{lat: float, lon: float}|null
     */
    private function findNearestShop(Coordinate $midpoint, array $shops): ?array
    {
        if ([] === $shops) {
            return null;
        }

        $minDist = PHP_FLOAT_MAX;
        $nearest = null;

        foreach ($shops as $shop) {
            $dist = $this->haversine->inMeters($midpoint->lat, $midpoint->lon, $shop['lat'], $shop['lon']);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $shop;
            }
        }

        return $nearest;
    }
}
