<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;

final readonly class ElevationAlertAnalyzer implements StageAnalyzerInterface
{
    private const float THRESHOLD_METERS = 1200.0;

    public function analyze(Stage $stage, array $context = []): array
    {
        if ($stage->elevation <= self::THRESHOLD_METERS) {
            return [];
        }

        return [new Alert(
            type: AlertType::WARNING,
            message: \sprintf(
                'Important dénivelé positif : %dm D+ sur cette étape.',
                (int) $stage->elevation,
            ),
            lat: $stage->startPoint->lat,
            lon: $stage->startPoint->lon,
        )];
    }

    public static function getPriority(): int
    {
        return 10;
    }
}
