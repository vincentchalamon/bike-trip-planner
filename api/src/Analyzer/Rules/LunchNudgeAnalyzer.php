<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;

final readonly class LunchNudgeAnalyzer implements StageAnalyzerInterface
{
    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace',
    ];

    public function analyze(Stage $stage, array $context = []): array
    {
        $pois = $stage->pois;

        foreach ($pois as $poi) {
            if (\in_array($poi->category, self::RESUPPLY_CATEGORIES, true)) {
                return [];
            }
        }

        if ($stage->distance < 20.0) {
            return [];
        }

        return [new Alert(
            type: AlertType::NUDGE,
            message: 'Aucun restaurant ou commerce alimentaire détecté sur cette étape. Prévoyez des provisions.',
            lat: $stage->startPoint->lat,
            lon: $stage->startPoint->lon,
        )];
    }

    public static function getPriority(): int
    {
        return 100;
    }
}
