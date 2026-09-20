<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Osm\ChargingStationRepositoryInterface;

final readonly class EbikeRangeAnalyzer implements StageAnalyzerInterface
{
    private const float BASE_RANGE_KM = 80.0;

    private const float ELEVATION_PENALTY_DIVISOR = 25.0;

    /** Corridor half-width (m) for the local-first charging-station reads (ADR-040). */
    private const int CORRIDOR_RADIUS_METERS = 2000;

    public function __construct(
        private ChargingStationRepositoryInterface $chargingStationRepository,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        // A rest day is not ridden: the battery is not drained, and it is where the
        // bike gets charged anyway.
        if ($stage->isRestDay) {
            return [];
        }

        if (true !== ($context['ebikeMode'] ?? false)) {
            return [];
        }

        $effectiveRange = max(0.0, self::BASE_RANGE_KM - ($stage->elevation / self::ELEVATION_PENALTY_DIVISOR));

        if ($stage->distance <= $effectiveRange) {
            return [];
        }

        // Point the cyclist to the nearest charger along the stage corridor (ADR-040).
        $nearestCharger = $this->findNearestCharger($stage);

        if (null !== $nearestCharger) {
            return [new Alert(
                code: AlertCode::EBIKE_RANGE_EXCEEDED,
                type: AlertType::WARNING,
                messageKey: 'alert.ebike_range.warning',
                parameters: $this->messageParameters($stage, $effectiveRange),
                lat: $nearestCharger['lat'],
                lon: $nearestCharger['lon'],
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.ebike_range.charging_action',
                    payload: [
                        'lat' => $nearestCharger['lat'],
                        'lon' => $nearestCharger['lon'],
                        'maxDistance' => round($effectiveRange, 1),
                    ],
                ),
            )];
        }

        // No charger in range: keep the original distance-reduction nudge.
        return [new Alert(
            code: AlertCode::EBIKE_RANGE_EXCEEDED,
            type: AlertType::WARNING,
            messageKey: 'alert.ebike_range.warning',
            parameters: $this->messageParameters($stage, $effectiveRange),
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                labelKey: 'alert.ebike_range.action',
                payload: ['maxDistance' => round($effectiveRange, 1)],
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }

    /** @return array<string, int> */
    private function messageParameters(Stage $stage, float $effectiveRange): array
    {
        return [
            '%distance%' => (int) round($stage->distance),
            '%range%' => (int) round($effectiveRange),
        ];
    }

    /**
     * The charging station nearest to the stage corridor, via the local-first index
     * (the DB resolves the closest one — see ChargingStationRepository).
     *
     * @return array{name: ?string, category: string, lat: float, lon: float}|null
     */
    private function findNearestCharger(Stage $stage): ?array
    {
        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $geometry);

        return $this->chargingStationRepository->findNearestInCorridor($route, self::CORRIDOR_RADIUS_METERS);
    }
}
