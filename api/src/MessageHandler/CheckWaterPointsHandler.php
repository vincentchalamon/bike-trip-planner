<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Geo\Nearest;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\Alert;
use App\Alert\AlertPayload;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Message\CheckWaterPoints;
use App\Osm\WaterPointRepositoryInterface;
use App\Repository\TransientTripPointsStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckWaterPointsHandler extends AbstractTripMessageHandler
{
    private const float WATER_GAP_THRESHOLD_KM = 30.0;

    /** Corridor half-width (m) for the local-first water reads (ADR-040). */
    private const int CORRIDOR_RADIUS_METERS = 2000;

    public function __construct(
        TripHandlerContext $context,
        private TransientTripPointsStoreInterface $points,
        private WaterPointRepositoryInterface $waterPointRepository,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $haversine,
    ) {
        parent::__construct($context);
    }

    public function __invoke(CheckWaterPoints $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages): void {
            $route = $this->routeCorridor($this->points, $tripId, $stages);

            // Read drinking-water points from the local-first index along the route corridor (ADR-040).
            $allWaterPoints = [];
            foreach ($this->waterPointRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $waterPoint) {
                $allWaterPoints[] = ['lat' => $waterPoint['lat'], 'lon' => $waterPoint['lon']];
            }

            /** @var array<int, list<array{lat: float, lon: float}>> $waterByStage */
            $waterByStage = $this->distributor->distributeByGeometry($allWaterPoints, $stages);

            $alerts = [];
            $waterPointsByStage = [];

            foreach ($stages as $i => $stage) {
                $stageWaterPoints = $waterByStage[$i] ?? [];
                $waterPointsWithDistance = $this->computeDistancesFromStart($stage, $stageWaterPoints);
                $waterPointsByStage[] = [
                    'stageId' => $stage->id,
                    'waterPoints' => $waterPointsWithDistance,
                ];

                // A rest day is not ridden: no on-route hydration gap to warn about.
                // Its water points still ship in the payload above (useful where you stay).
                if (!$stage->isRestDay && $this->hasWaterGap($stage, $waterPointsWithDistance)) {
                    $nearestWp = Nearest::to($this->haversine, $stage->midpoint(), $allWaterPoints);
                    $alerts[] = AlertPayload::forStage($stage, new Alert(
                        code: AlertCode::WATER_POINT_GAP,
                        type: AlertType::NUDGE,
                        messageKey: 'alert.water.nudge',
                        parameters: ['%threshold%' => self::WATER_GAP_THRESHOLD_KM * 1000],
                        parameterFormats: ['%threshold%' => AlertParameterFormat::DISTANCE->value],
                        action: null !== $nearestWp
                            ? new AlertAction(AlertActionKind::NAVIGATE, 'alert.water.action', ['lat' => $nearestWp['lat'], 'lon' => $nearestWp['lon']])
                            : null,
                    ));
                }
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::WATER_POINT, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::WATER_POINT_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
                'waterPointsByStage' => $waterPointsByStage,
            ]);
        });
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
            $nearestIndex = Nearest::vertexIndex($this->haversine, $geometry, $wp['lat'], $wp['lon']);
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
}
