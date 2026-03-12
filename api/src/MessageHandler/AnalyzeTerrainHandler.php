<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyzeTerrainHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private AnalyzerRegistryInterface $analyzerRegistry,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $geoDistance,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(AnalyzeTerrain $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $ebikeMode = (bool) $this->tripStateManager->getRequest($tripId)?->ebikeMode;

        $this->executeWithTracking($tripId, ComputationName::TERRAIN, function () use ($tripId, $stages, $locale, $ebikeMode): void {
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
        });
    }

    /**
     * Fetches OSM ways along the route and distributes them to stages.
     *
     * @param list<Stage> $stages
     *
     * @return array<int, list<array{lat: float, lon: float}>>
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

        $query = $this->queryBuilder->buildWaysQuery($points);
        $result = $this->scanner->query($query);

        /** @var list<array{tags?: array<string, string>, geometry?: list<array{lat: float, lon: float}>}> $elements */
        $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

        $parsedWays = [];
        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $geometry = $element['geometry'] ?? [];
            $length = $this->computeWayLength($geometry);
            $center = $this->computeWayCenter($geometry);

            if (null === $center) {
                continue;
            }

            $way = [
                'lat' => $center['lat'],
                'lon' => $center['lon'],
                'surface' => $tags['surface'] ?? '',
                'highway' => $tags['highway'] ?? '',
                'cycleway' => $tags['cycleway'] ?? '',
                'cycleway:right' => $tags['cycleway:right'] ?? '',
                'cycleway:left' => $tags['cycleway:left'] ?? '',
                'length' => $length,
            ];

            $parsedWays[] = $way;
        }

        return $this->distributor->distributeByGeometry($parsedWays, $stages);
    }

    /**
     * Computes the length of a way in meters from its geometry nodes.
     *
     * @param list<array{lat: float, lon: float}> $geometry
     */
    private function computeWayLength(array $geometry): float
    {
        $length = 0.0;

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $length += $this->geoDistance->inMeters(
                $geometry[$i - 1]['lat'],
                $geometry[$i - 1]['lon'],
                $geometry[$i]['lat'],
                $geometry[$i]['lon'],
            );
        }

        return $length;
    }

    /**
     * Returns the midpoint of a way geometry.
     *
     * @param list<array{lat: float, lon: float}> $geometry
     *
     * @return array{lat: float, lon: float}|null
     */
    private function computeWayCenter(array $geometry): ?array
    {
        if ([] === $geometry) {
            return null;
        }

        $midIndex = (int) ((\count($geometry) - 1) / 2);

        return ['lat' => $geometry[$midIndex]['lat'], 'lon' => $geometry[$midIndex]['lon']];
    }
}
