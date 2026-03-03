<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LunchNudgeAnalyzer implements StageAnalyzerInterface
{
    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

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

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return [new Alert(
            type: AlertType::NUDGE,
            message: $this->translator->trans('alert.lunch.nudge', [], 'alerts', $locale),
            lat: $stage->startPoint->lat,
            lon: $stage->startPoint->lon,
        )];
    }

    public static function getPriority(): int
    {
        return 100;
    }
}
