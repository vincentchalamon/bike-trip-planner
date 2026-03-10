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
use App\Message\CheckChargingPoints;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class CheckChargingPointsHandler extends AbstractTripMessageHandler
{
    private const float CHARGING_POINT_PROXIMITY_METERS = 5000.0;

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

    public function __invoke(CheckChargingPoints $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::CHARGING_POINTS, function () use ($tripId, $stages, $locale): void {
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $query = $this->queryBuilder->buildChargingPointQuery($points);
            $result = $this->scanner->query($query);

            /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            $chargingPointLocations = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $chargingPointLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }

            $stagesWithoutCharging = [];
            foreach ($stages as $i => $stage) {
                $hasNearby = false;
                $endPoint = $stage->endPoint;

                foreach ($chargingPointLocations as $point) {
                    if ($this->haversine->inMeters($endPoint->lat, $endPoint->lon, $point['lat'], $point['lon']) < self::CHARGING_POINT_PROXIMITY_METERS) {
                        $hasNearby = true;
                        break;
                    }
                }

                if (!$hasNearby) {
                    $stagesWithoutCharging[] = [
                        'stageIndex' => $i,
                        'dayNumber' => $stage->dayNumber,
                        'type' => AlertType::NUDGE->value,
                        'message' => $this->translator->trans(
                            'alert.charging_point.nudge',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CHARGING_POINT_ALERTS, [
                'alerts' => $stagesWithoutCharging,
            ]);
        });
    }
}
