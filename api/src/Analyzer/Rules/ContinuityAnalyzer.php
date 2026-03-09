<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ContinuityAnalyzer implements StageAnalyzerInterface
{
    private const float CRITICAL_THRESHOLD_METERS = 500.0;

    private const float WARNING_THRESHOLD_METERS = 100.0;

    public function __construct(
        private DistanceCalculatorInterface $distanceCalculator,
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var Stage|null $nextStage */
        $nextStage = $context['nextStage'] ?? null;

        if (null === $nextStage) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        $gapMeters = $this->distanceCalculator->distanceBetween($stage->endPoint, $nextStage->startPoint);

        if ($gapMeters > self::CRITICAL_THRESHOLD_METERS) {
            return [new Alert(
                type: AlertType::CRITICAL,
                message: $this->translator->trans(
                    'alert.continuity.critical',
                    [
                        '%distance%' => number_format($gapMeters / 1000, 1),
                        '%from%' => $stage->dayNumber,
                        '%to%' => $nextStage->dayNumber,
                    ],
                    'alerts',
                    $locale,
                ),
                lat: $stage->endPoint->lat,
                lon: $stage->endPoint->lon,
            )];
        }

        if ($gapMeters > self::WARNING_THRESHOLD_METERS) {
            return [new Alert(
                type: AlertType::WARNING,
                message: $this->translator->trans(
                    'alert.continuity.warning',
                    [
                        '%gap%' => (int) $gapMeters,
                        '%from%' => $stage->dayNumber,
                        '%to%' => $nextStage->dayNumber,
                    ],
                    'alerts',
                    $locale,
                ),
                lat: $stage->endPoint->lat,
                lon: $stage->endPoint->lon,
            )];
        }

        return [];
    }

    public static function getPriority(): int
    {
        return 5;
    }
}
