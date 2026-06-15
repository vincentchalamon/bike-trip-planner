<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use App\Geo\GeoDistanceInterface;
use App\Osm\ChargingStationRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class EbikeRangeAnalyzer implements StageAnalyzerInterface
{
    private const float BASE_RANGE_KM = 80.0;

    private const float ELEVATION_PENALTY_DIVISOR = 25.0;

    /** Corridor half-width (m) for the local-first charging-station reads (ADR-040). */
    private const int CORRIDOR_RADIUS_METERS = 2000;

    public function __construct(
        private TranslatorInterface $translator,
        private ChargingStationRepositoryInterface $chargingStationRepository,
        private GeoDistanceInterface $haversine,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        if (true !== ($context['ebikeMode'] ?? false)) {
            return [];
        }

        $effectiveRange = max(0.0, self::BASE_RANGE_KM - ($stage->elevation / self::ELEVATION_PENALTY_DIVISOR));

        if ($stage->distance <= $effectiveRange) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        // Point the cyclist to the nearest charger along the stage corridor (ADR-040).
        $nearestCharger = $this->findNearestCharger($stage);

        if (null !== $nearestCharger) {
            return [new Alert(
                type: AlertType::WARNING,
                message: $this->buildMessage($stage, $effectiveRange, $locale),
                lat: $nearestCharger['lat'],
                lon: $nearestCharger['lon'],
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    label: $this->translator->trans('alert.ebike_range.charging_action', [], 'alerts', $locale),
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
            type: AlertType::WARNING,
            message: $this->buildMessage($stage, $effectiveRange, $locale),
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                label: $this->translator->trans('alert.ebike_range.action', [], 'alerts', $locale),
                payload: ['maxDistance' => round($effectiveRange, 1)],
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }

    private function buildMessage(Stage $stage, float $effectiveRange, string $locale): string
    {
        return $this->translator->trans(
            'alert.ebike_range.warning',
            [
                '%stage%' => $stage->dayNumber,
                '%distance%' => (int) round($stage->distance),
                '%range%' => (int) round($effectiveRange),
            ],
            'alerts',
            $locale,
        );
    }

    /**
     * Finds the charging station nearest to the stage geometry, restricted to the
     * stage corridor via the local-first index.
     *
     * @return array{lat: float, lon: float}|null
     */
    private function findNearestCharger(Stage $stage): ?array
    {
        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $geometry);

        $minDist = PHP_FLOAT_MAX;
        $nearest = null;

        foreach ($this->chargingStationRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $charger) {
            foreach ($geometry as $point) {
                $dist = $this->haversine->inMeters($point->lat, $point->lon, $charger['lat'], $charger['lon']);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearest = ['lat' => $charger['lat'], 'lon' => $charger['lon']];
                }
            }
        }

        return $nearest;
    }
}
