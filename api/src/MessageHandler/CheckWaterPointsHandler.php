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
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckWaterPoints;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class CheckWaterPointsHandler extends AbstractTripMessageHandler
{
    private const float WATER_GAP_THRESHOLD_KM = 30.0;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $haversine,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(CheckWaterPoints $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::WATER_POINTS, function () use ($tripId, $stages, $locale): void {
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $query = $this->queryBuilder->buildCemeteryQuery($points);
            $result = $this->scanner->query($query);

            /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            $allWaterPoints = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $allWaterPoints[] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }

            /** @var array<int, list<array{lat: float, lon: float}>> $waterByStage */
            $waterByStage = $this->distributor->distributeByGeometry($allWaterPoints, $stages);

            $alerts = [];
            $waterPointsByStage = [];

            foreach ($stages as $i => $stage) {
                $stageWaterPoints = $waterByStage[$i] ?? [];
                $waterPointsWithDistance = $this->computeDistancesFromStart($stage, $stageWaterPoints);
                $waterPointsByStage[] = [
                    'stageIndex' => $i,
                    'waterPoints' => $waterPointsWithDistance,
                ];

                if ($this->hasWaterGap($stage, $waterPointsWithDistance)) {
                    $nearestWp = $this->findNearestWaterPoint($stage, $allWaterPoints);
                    $alerts[] = [
                        'stageIndex' => $i,
                        'dayNumber' => $stage->dayNumber,
                        'type' => AlertType::NUDGE->value,
                        'message' => $this->translator->trans(
                            'alert.cemetery.nudge',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                        'action' => null !== $nearestWp ? [
                            'kind' => AlertActionKind::NAVIGATE->value,
                            'label' => $this->translator->trans('alert.cemetery.action', [], 'alerts', $locale),
                            'payload' => ['lat' => $nearestWp['lat'], 'lon' => $nearestWp['lon']],
                        ] : null,
                    ];
                }
            }

            $this->publisher->publish($tripId, MercureEventType::WATER_POINT_ALERTS, [
                'alerts' => $alerts,
                'waterPointsByStage' => $waterPointsByStage,
            ]);
        }, $generation);
    }

    /**
     * Computes the approximate distance from stage start for each water point.
     *
     * @param list<array{lat: float, lon: float}> $waterPoints
     *
     * @return list<array{lat: float, lon: float, distanceFromStart: float}>
     */
    private function computeDistancesFromStart(Stage $stage, array $waterPoints): array
    {
        if ([] === $waterPoints) {
            return [];
        }

        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $cumulativeDistances = $this->buildCumulativeDistances($geometry);

        $result = [];
        foreach ($waterPoints as $wp) {
            $nearestIndex = $this->findNearestGeometryIndex($geometry, $wp['lat'], $wp['lon']);
            $result[] = [
                'lat' => $wp['lat'],
                'lon' => $wp['lon'],
                'distanceFromStart' => round($cumulativeDistances[$nearestIndex], 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        return $result;
    }

    /**
     * Checks whether a stage has a stretch > 30 km without any water point.
     *
     * @param list<array{lat: float, lon: float, distanceFromStart: float}> $waterPoints sorted by distance
     */
    private function hasWaterGap(Stage $stage, array $waterPoints): bool
    {
        $stageLengthKm = $stage->distance;

        if ([] === $waterPoints) {
            return $stageLengthKm > self::WATER_GAP_THRESHOLD_KM;
        }

        // Check gap from start to first water point
        if ($waterPoints[0]['distanceFromStart'] > self::WATER_GAP_THRESHOLD_KM) {
            return true;
        }

        // Check gaps between consecutive water points
        for ($j = 1, $count = \count($waterPoints); $j < $count; ++$j) {
            if (($waterPoints[$j]['distanceFromStart'] - $waterPoints[$j - 1]['distanceFromStart']) > self::WATER_GAP_THRESHOLD_KM) {
                return true;
            }
        }

        // Check gap from last water point to end
        $lastDistance = $waterPoints[\count($waterPoints) - 1]['distanceFromStart'];

        return ($stageLengthKm - $lastDistance) > self::WATER_GAP_THRESHOLD_KM;
    }

    /**
     * Builds an array of cumulative distances (in km) along the geometry.
     *
     * @param list<Coordinate> $geometry
     *
     * @return list<float>
     */
    private function buildCumulativeDistances(array $geometry): array
    {
        $cumulative = [0.0];

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $prev = $geometry[$i - 1];
            $curr = $geometry[$i];
            $cumulative[] = $cumulative[$i - 1] + $this->haversine->inKilometers($prev->lat, $prev->lon, $curr->lat, $curr->lon);
        }

        return $cumulative;
    }

    /**
     * Finds the index of the closest geometry point to the given coordinates.
     *
     * @param list<Coordinate> $geometry
     */
    private function findNearestGeometryIndex(array $geometry, float $lat, float $lon): int
    {
        $minDist = PHP_FLOAT_MAX;
        $nearest = 0;

        foreach ($geometry as $i => $point) {
            $dist = $this->haversine->inMeters($point->lat, $point->lon, $lat, $lon);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $i;
            }
        }

        return $nearest;
    }

    /**
     * Finds the nearest water point to the stage midpoint.
     *
     * @param list<array{lat: float, lon: float}> $allWaterPoints
     *
     * @return array{lat: float, lon: float}|null
     */
    private function findNearestWaterPoint(Stage $stage, array $allWaterPoints): ?array
    {
        if ([] === $allWaterPoints) {
            return null;
        }

        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $midpoint = $geometry[(int) (\count($geometry) / 2)];

        $minDist = PHP_FLOAT_MAX;
        $nearest = null;

        foreach ($allWaterPoints as $wp) {
            $dist = $this->haversine->inMeters($midpoint->lat, $midpoint->lon, $wp['lat'], $wp['lon']);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $wp;
            }
        }

        return $nearest;
    }
}
