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
use App\Osm\HealthServiceRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checks for pharmacies, hospitals and clinics within 15 km of each stage.
 *
 * Emits a NUDGE alert when no health service is found near a stage.
 */
#[AsMessageHandler]
final readonly class CheckHealthServicesHandler extends AbstractTripMessageHandler
{
    private const float HEALTH_SERVICE_PROXIMITY_METERS = 15000.0;

    /** Corridor half-width (m) for the local-first health-service reads (ADR-040); kept equal to HEALTH_SERVICE_PROXIMITY_METERS so the DB pre-filter always covers the per-stage proximity threshold. */
    private const int CORRIDOR_RADIUS_METERS = (int) self::HEALTH_SERVICE_PROXIMITY_METERS;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private HealthServiceRepositoryInterface $healthServiceRepository,
        private GeoDistanceInterface $haversine,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
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
            // Read health services from the local-first index along the route corridor (ADR-040).
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $points);

            /** @var list<array{lat: float, lon: float}> $healthServiceLocations */
            $healthServiceLocations = [];
            foreach ($this->healthServiceRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $service) {
                $healthServiceLocations[] = ['lat' => $service['lat'], 'lon' => $service['lon']];
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
