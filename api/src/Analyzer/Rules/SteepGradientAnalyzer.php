<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertType;

final readonly class SteepGradientAnalyzer implements StageAnalyzerInterface
{
    private const float MIN_GRADIENT_PERCENT = 8.0;

    private const float MIN_DISTANCE_METERS = 500.0;

    public function __construct(
        private DistanceCalculatorInterface $distanceCalculator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        // A rest day is not ridden: it has no climb to report.
        if ($stage->isRestDay) {
            return [];
        }

        $geometry = $stage->geometry;
        if (\count($geometry) < 2) {
            return [];
        }

        $alerts = [];
        $sectionStart = 0;
        $sectionDistance = 0.0;
        $sectionElevationGain = 0.0;
        $inSteepSection = false;

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $prev = $geometry[$i - 1];
            $curr = $geometry[$i];
            $segmentDistance = $this->distanceCalculator->distanceBetween($prev, $curr);
            $elevationDiff = $curr->ele - $prev->ele;

            $gradient = $segmentDistance > 0 ? ($elevationDiff / $segmentDistance) * 100.0 : 0.0;

            if ($gradient >= self::MIN_GRADIENT_PERCENT) {
                if (!$inSteepSection) {
                    $inSteepSection = true;
                    $sectionStart = $i - 1;
                    $sectionDistance = 0.0;
                    $sectionElevationGain = 0.0;
                }

                $sectionDistance += $segmentDistance;
                $sectionElevationGain += $elevationDiff;
            } elseif ($inSteepSection) {
                $alert = $this->buildAlertIfQualified($geometry[$sectionStart], $sectionDistance, $sectionElevationGain);
                if ($alert instanceof Alert) {
                    $alerts[] = $alert;
                }

                $inSteepSection = false;
            }
        }

        // Flush trailing steep section
        if ($inSteepSection) {
            $alert = $this->buildAlertIfQualified($geometry[$sectionStart], $sectionDistance, $sectionElevationGain);
            if ($alert instanceof Alert) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    public static function getPriority(): int
    {
        return 20;
    }

    private function buildAlertIfQualified(Coordinate $start, float $distance, float $elevationGain): ?Alert
    {
        if ($distance < self::MIN_DISTANCE_METERS) {
            return null;
        }

        $averageGradient = $distance > 0 ? ($elevationGain / $distance) * 100.0 : 0.0;

        return new Alert(
            code: AlertCode::STEEP_GRADIENT,
            type: AlertType::WARNING,
            messageKey: 'alert.steep_gradient.warning',
            parameters: [
                '%gradient%' => $averageGradient,
                '%distance%' => $distance,
            ],
            parameterFormats: [
                '%gradient%' => AlertParameterFormat::DECIMAL_ONE->value,
                '%distance%' => AlertParameterFormat::DISTANCE->value,
            ],
            lat: $start->lat,
            lon: $start->lon,
            action: new AlertAction(
                kind: AlertActionKind::NAVIGATE,
                labelKey: 'alert.steep_gradient.action',
                payload: ['lat' => $start->lat, 'lon' => $start->lon],
            ),
        );
    }
}
