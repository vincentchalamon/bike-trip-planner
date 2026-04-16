<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckHealthServices;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checks for pharmacies, hospitals and clinics within 15 km of each stage.
 *
 * Emits a NUDGE alert when no health service is found near a stage.
 */
#[AsMessageHandler]
final readonly class CheckHealthServicesHandler extends AbstractTripMessageHandler
{
    /** Must be ≤ OsmOverpassQueryBuilder::HEALTH_SERVICE_RADIUS_METERS to avoid false alerts. */
    private const float HEALTH_SERVICE_PROXIMITY_METERS = 15000.0;

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
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(CheckHealthServices $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::HEALTH_SERVICES, function () use ($tripId, $stages, $locale): void {
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $query = $this->queryBuilder->buildHealthServiceQuery($points);
            $result = $this->scanner->query($query);

            /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}, tags?: array<string, string>}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse health service locations
            /** @var list<array{lat: float, lon: float}> $healthServiceLocations */
            $healthServiceLocations = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $healthServiceLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }

            // Check each stage for nearby health services
            $alerts = [];
            foreach ($stages as $i => $stage) {
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $midpoint = $geometry[(int) (\count($geometry) / 2)];

                $hasNearby = false;
                foreach ($healthServiceLocations as $service) {
                    $distance = $this->haversine->inMeters($midpoint->lat, $midpoint->lon, $service['lat'], $service['lon']);
                    if ($distance < self::HEALTH_SERVICE_PROXIMITY_METERS) {
                        $hasNearby = true;
                        break;
                    }
                }

                if ($hasNearby) {
                    continue;
                }

                $alerts[] = [
                    'stageIndex' => $i,
                    'dayNumber' => $stage->dayNumber,
                    'type' => AlertType::NUDGE->value,
                    'message' => $this->translator->trans(
                        'alert.health_service.nudge',
                        ['%stage%' => $stage->dayNumber],
                        'alerts',
                        $locale,
                    ),
                ];
            }

            $this->publisher->publish($tripId, MercureEventType::HEALTH_SERVICE_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }
}
