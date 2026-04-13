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
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SteepGradientAnalyzer implements StageAnalyzerInterface
{
    private const float MIN_GRADIENT_PERCENT = 8.0;

    private const float MIN_DISTANCE_METERS = 500.0;

    public function __construct(
        private DistanceCalculatorInterface $distanceCalculator,
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        $geometry = $stage->geometry;
        if (\count($geometry) < 2) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

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
                $alert = $this->buildAlertIfQualified($geometry[$sectionStart], $sectionDistance, $sectionElevationGain, $locale);
                if ($alert instanceof Alert) {
                    $alerts[] = $alert;
                }

                $inSteepSection = false;
            }
        }

        // Flush trailing steep section
        if ($inSteepSection) {
            $alert = $this->buildAlertIfQualified($geometry[$sectionStart], $sectionDistance, $sectionElevationGain, $locale);
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

    private function buildAlertIfQualified(Coordinate $start, float $distance, float $elevationGain, string $locale): ?Alert
    {
        if ($distance < self::MIN_DISTANCE_METERS) {
            return null;
        }

        $averageGradient = $distance > 0 ? ($elevationGain / $distance) * 100.0 : 0.0;

        return new Alert(
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.steep_gradient.warning',
                [
                    '%gradient%' => round($averageGradient, 1),
                    '%distance%' => (int) $distance,
                ],
                'alerts',
                $locale,
            ),
            lat: $start->lat,
            lon: $start->lon,
            action: new AlertAction(
                kind: AlertActionKind::NAVIGATE,
                label: $this->translator->trans('alert.steep_gradient.action', [], 'alerts', $locale),
                payload: ['lat' => $start->lat, 'lon' => $start->lon],
            ),
        );
    }
}
