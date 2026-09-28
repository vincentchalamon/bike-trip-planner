<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Geo\Nearest;
use App\ApiResource\Model\Alert;
use App\Alert\AlertPayload;
use App\Alert\AlertRenderer;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckHealthServices;
use App\Osm\HealthServiceRepositoryInterface;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Checks for pharmacies, hospitals and clinics within 15 km of each stage.
 *
 * Emits a NUDGE alert when no health service is found near a stage.
 *
 * Deliberately has no `isRestDay` guard: the check is evaluated at the stage midpoint,
 * which for a rest day is the place the rider stays for a whole day. Knowing there is
 * no pharmacy within 15 km is at least as useful there as on a transit day.
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
        TripRequestRepositoryInterface $tripRequestRepository,
        TripStageStoreInterface $stageStore,
        private TransientTripPointsStoreInterface $points,
        private HealthServiceRepositoryInterface $healthServiceRepository,
        private GeoDistanceInterface $haversine,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripRequestRepository, $stageStore, $messageBus, $alertRenderer);
    }

    public function __invoke(CheckHealthServices $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::HEALTH_SERVICES, function () use ($tripId, $stages): void {
            // Read health services from the local-first index along the route corridor (ADR-040).
            $route = $this->routeCorridor($this->points, $tripId, $stages);

            /** @var list<array{lat: float, lon: float}> $healthServiceLocations */
            $healthServiceLocations = [];
            foreach ($this->healthServiceRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $service) {
                $healthServiceLocations[] = ['lat' => $service['lat'], 'lon' => $service['lon']];
            }

            // Check each stage for nearby health services
            $alerts = [];
            foreach ($stages as $stage) {
                if (Nearest::anyWithin($this->haversine, $stage->midpoint(), $healthServiceLocations, self::HEALTH_SERVICE_PROXIMITY_METERS)) {
                    continue;
                }

                $alerts[] = AlertPayload::forStage($stage, new Alert(
                    code: AlertCode::HEALTH_SERVICE_NONE_NEARBY,
                    type: AlertType::NUDGE,
                    messageKey: 'alert.health_service.nudge',
                    parameters: ['%threshold%' => self::HEALTH_SERVICE_PROXIMITY_METERS],
                    parameterFormats: ['%threshold%' => AlertParameterFormat::DISTANCE->value],
                ));
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::HEALTH_SERVICE, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::HEALTH_SERVICE_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        });
    }
}
