<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class GenerateStagesHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private PacingEngineInterface $pacingEngine,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(GenerateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripStateManager->getRequest($tripId);

        if (!$request instanceof TripRequest) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::STAGES, function () use ($tripId, $request, $generation): void {
            $sourceType = $this->tripStateManager->getSourceType($tripId);

            if ($sourceType === SourceType::KOMOOT_COLLECTION->value) {
                $stages = $this->generateCollectionStages($tripId);
            } else {
                $stages = $this->generatePacingStages($tripId, $request);
            }

            if (\count($stages) < 2) {
                $this->publisher->publishValidationError($tripId, 'MIN_STAGES', 'A minimum of 2 stages is required.');
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $this->publisher->publish($tripId, MercureEventType::STAGES_COMPUTED, [
                'stages' => array_map(
                    static fn (Stage $s): array => [
                        'dayNumber' => $s->dayNumber,
                        'distance' => round($s->distance, 1),
                        'elevation' => (int) $s->elevation,
                        'elevationLoss' => (int) $s->elevationLoss,
                        'startPoint' => [
                            'lat' => $s->startPoint->lat,
                            'lon' => $s->startPoint->lon,
                            'ele' => $s->startPoint->ele,
                        ],
                        'endPoint' => [
                            'lat' => $s->endPoint->lat,
                            'lon' => $s->endPoint->lon,
                            'ele' => $s->endPoint->ele,
                        ],
                        'label' => $s->label,
                        'geometry' => array_map(
                            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                            $s->geometry,
                        ),
                    ],
                    $stages,
                ),
            ]);

            $this->messageBus->dispatch(new ScanPois($tripId, $generation));
            $this->messageBus->dispatch(new ScanAccommodations($tripId, enabledAccommodationTypes: $request->enabledAccommodationTypes, generation: $generation));
            $this->messageBus->dispatch(new AnalyzeTerrain($tripId, $generation));
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
            $this->messageBus->dispatch(new CheckBikeShops($tripId, $generation));
            $this->messageBus->dispatch(new CheckWaterPoints($tripId, $generation));
            $this->messageBus->dispatch(new CheckHealthServices($tripId, $generation));
            $this->messageBus->dispatch(new CheckCulturalPois($tripId, $generation));
            $this->messageBus->dispatch(new CheckRailwayStations($tripId, $generation));
        }, $generation);
    }

    /** @return list<Stage> */
    private function generateCollectionStages(string $tripId): array
    {
        $tracksData = $this->tripStateManager->getTracksData($tripId);

        if (null === $tracksData) {
            return [];
        }

        $stages = [];

        foreach ($tracksData as $i => $trackData) {
            $points = array_map(
                static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
                $trackData,
            );

            if ([] === $points) {
                continue;
            }

            $distance = $this->distanceCalculator->calculateTotalDistance($points);
            $elevation = $this->elevationCalculator->calculateTotalAscent($points);
            $elevationLoss = $this->elevationCalculator->calculateTotalDescent($points);
            $geometry = $this->routeSimplifier->simplify($points);

            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $i + 1,
                distance: $distance,
                elevation: $elevation,
                startPoint: $points[0],
                endPoint: $points[\count($points) - 1],
                geometry: $geometry,
                elevationLoss: $elevationLoss,
            );
        }

        return $stages;
    }

    /**
     * @return list<Stage>
     */
    private function generatePacingStages(string $tripId, TripRequest $request): array
    {
        $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);

        if (null === $decimatedData) {
            return [];
        }

        $decimatedPoints = array_map(
            static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
            $decimatedData,
        );

        $allPointsData = $this->tripStateManager->getRawPoints($tripId);
        $allPoints = null !== $allPointsData
            ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $allPointsData)
            : $decimatedPoints;

        $totalDistance = $this->distanceCalculator->calculateTotalDistance($allPoints);

        if ($request->endDate instanceof \DateTimeImmutable && $request->startDate instanceof \DateTimeImmutable) {
            $numberOfDays = (int) $request->startDate->diff($request->endDate)->days + 1;
        } else {
            $numberOfDays = (int) ceil($totalDistance / $request->maxDistancePerDay);
            $numberOfDays = max(1, $numberOfDays);
        }

        // Pass raw points for accurate elevation calculation (decimated points lose altitude detail).
        // rawPoints is null when no full-resolution data was loaded (e.g. routing-only trips),
        // in which case the pacing engine falls back to decimated points.
        $rawPoints = null !== $allPointsData ? $allPoints : null;

        return $this->pacingEngine->generateStages(
            $tripId,
            $decimatedPoints,
            $numberOfDays,
            $totalDistance,
            $request->fatigueFactor,
            $request->elevationPenalty,
            $rawPoints,
            $request->maxDistancePerDay,
        );
    }
}
