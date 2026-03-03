<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ElevationAlertAnalyzer implements StageAnalyzerInterface
{
    private const float THRESHOLD_METERS = 1200.0;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        if ($stage->elevation <= self::THRESHOLD_METERS) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return [new Alert(
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.elevation.warning',
                ['%elevation%' => (int) $stage->elevation],
                'alerts',
                $locale,
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
