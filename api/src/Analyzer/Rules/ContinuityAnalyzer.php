<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertType;

/**
 * Deliberately has no `isRestDay` guard: a rest day duplicates the previous stage's
 * end point as both its start and end, so the rest-day → next-stage check IS the real
 * continuity check between the two ridden stages around it. Skipping rest days would
 * silently drop a genuine route gap.
 */
final readonly class ContinuityAnalyzer implements StageAnalyzerInterface
{
    private const float CRITICAL_THRESHOLD_METERS = 500.0;

    private const float WARNING_THRESHOLD_METERS = 100.0;

    public function __construct(
        private DistanceCalculatorInterface $distanceCalculator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var Stage|null $nextStage */
        $nextStage = $context['nextStage'] ?? null;

        if (null === $nextStage) {
            return [];
        }

        $gapMeters = $this->distanceCalculator->distanceBetween($stage->endPoint, $nextStage->startPoint);

        if ($gapMeters > self::CRITICAL_THRESHOLD_METERS) {
            return [new Alert(
                code: AlertCode::CONTINUITY_GAP_CRITICAL,
                type: AlertType::CRITICAL,
                messageKey: 'alert.continuity.critical',
                parameters: ['%distance%' => $gapMeters],
                parameterFormats: ['%distance%' => AlertParameterFormat::DISTANCE_KM->value],
                lat: $stage->endPoint->lat,
                lon: $stage->endPoint->lon,
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.continuity.action',
                    payload: ['lat' => $stage->endPoint->lat, 'lon' => $stage->endPoint->lon],
                ),
            )];
        }

        if ($gapMeters > self::WARNING_THRESHOLD_METERS) {
            return [new Alert(
                code: AlertCode::CONTINUITY_GAP_WARNING,
                type: AlertType::WARNING,
                messageKey: 'alert.continuity.warning',
                parameters: ['%gap%' => (int) $gapMeters],
                lat: $stage->endPoint->lat,
                lon: $stage->endPoint->lon,
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.continuity.action',
                    payload: ['lat' => $stage->endPoint->lat, 'lon' => $stage->endPoint->lon],
                ),
            )];
        }

        return [];
    }

    public static function getPriority(): int
    {
        return 5;
    }
}
