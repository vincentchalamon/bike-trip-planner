<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertType;

final readonly class ElevationAlertAnalyzer implements StageAnalyzerInterface
{
    private const float THRESHOLD_METERS = 1200.0;

    public function analyze(Stage $stage, array $context = []): array
    {
        // A rest day is not ridden: it has no climbing to report.
        if ($stage->isRestDay) {
            return [];
        }

        if ($stage->elevation <= self::THRESHOLD_METERS) {
            return [];
        }

        $splitAtKm = round($stage->distance / 2, 1);

        return [new Alert(
            code: AlertCode::ELEVATION_GAIN,
            type: AlertType::WARNING,
            messageKey: 'alert.elevation.warning',
            parameters: ['%elevation%' => (int) $stage->elevation],
            lat: $stage->startPoint->lat,
            lon: $stage->startPoint->lon,
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                labelKey: 'alert.elevation.action',
                payload: ['splitAtKm' => $splitAtKm],
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 10;
    }
}
