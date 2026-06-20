<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Osm\WaysRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class AnalyzeTerrainHandler extends AbstractTripMessageHandler
{
    /** Corridor half-width (m) for the local-first ways reads (ADR-040). */
    private const int WAYS_CORRIDOR_RADIUS_METERS = 100;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private AnalyzerRegistryInterface $analyzerRegistry,
        private WaysRepositoryInterface $waysRepository,
        private GeometryDistributorInterface $distributor,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(AnalyzeTerrain $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages || [] === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $request = $this->tripStateManager->getRequest($tripId);
        $ebikeMode = (bool) $request?->ebikeMode;
        $startDate = $request?->startDate;
        $departureHour = $request?->departureHour ?? 8; // @phpstan-ignore nullsafe.neverNull
        $averageSpeed = $request?->averageSpeed ?? 15.0; // @phpstan-ignore nullsafe.neverNull

        $this->executeWithTracking($tripId, ComputationName::TERRAIN, function () use ($tripId, $stages, $locale, $ebikeMode, $startDate, $departureHour, $averageSpeed): void {
            $waysByStage = $this->fetchOsmWaysByStage($tripId, $stages);
            $stageCount = \count($stages);

            for ($i = 0; $i < $stageCount; ++$i) {
                $stage = $stages[$i];
                $context = [
                    'nextStage' => $stages[$i + 1] ?? null,
                    'tripDays' => $stageCount,
                    'locale' => $locale,
                    'ebikeMode' => $ebikeMode,
                    'osmWays' => $waysByStage[$i] ?? [],
                    'allStages' => $stages,
                    'startDate' => $startDate,
                    'stageIndex' => $i,
                    'departureHour' => $departureHour,
                    'averageSpeed' => $averageSpeed,
                ];

                $stage->alerts = [];
                $alerts = $this->analyzerRegistry->analyze($stage, $context);
                foreach ($alerts as $alert) {
                    $stage->addAlert($alert);
                }
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $alertsData = [];
            foreach ($stages as $i => $stage) {
                $alertsData[$i] = array_map(
                    static fn (Alert $a): array => ['type' => $a->type->value, 'message' => $a->message],
                    $stage->alerts,
                );
            }

            $this->publisher->publish($tripId, MercureEventType::TERRAIN_ALERTS, [
                'alertsByStage' => $alertsData,
            ]);
        }, $generation);
    }

    /**
     * Reads OSM ways along the route from the local-first index and distributes
     * them to stages (ADR-040). The index already reduces each way to its centroid,
     * length (m) and surface/traffic tags, so no per-way geometry math is needed here.
     *
     * @param list<Stage> $stages
     *
     * @return array<int, list<array{lat: float, lon: float, surface: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float}>>
     */
    private function fetchOsmWaysByStage(string $tripId, array $stages): array
    {
        $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
        $points = null !== $decimatedData
            ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
            : array_merge(...array_map(
                static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                $stages,
            ));

        $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $points);

        $ways = $this->waysRepository->findInCorridor($route, self::WAYS_CORRIDOR_RADIUS_METERS);

        return $this->distributor->distributeByGeometry($ways, $stages);
    }
}
