<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculator;
use App\Enum\AlertType;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ContinuityAnalyzer implements StageAnalyzerInterface
{
    private const float CRITICAL_THRESHOLD_METERS = 500.0;

    private const float WARNING_THRESHOLD_METERS = 100.0;

    public function __construct(
        #[Autowire(service: 'app.engine_registry')]
        private ContainerInterface $engineRegistry,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var Stage|null $nextStage */
        $nextStage = $context['nextStage'] ?? null;

        if (null === $nextStage) {
            return [];
        }

        $gapMeters = $this->engineRegistry
            ->get(DistanceCalculator::class)
            ->distanceBetween($stage->endPoint, $nextStage->startPoint);

        if ($gapMeters > self::CRITICAL_THRESHOLD_METERS) {
            return [new Alert(
                type: AlertType::CRITICAL,
                message: \sprintf(
                    'Discontinuité : %s km entre étape %d et %d.',
                    number_format($gapMeters / 1000, 1),
                    $stage->dayNumber,
                    $nextStage->dayNumber,
                ),
                lat: $stage->endPoint->lat,
                lon: $stage->endPoint->lon,
            )];
        }

        if ($gapMeters > self::WARNING_THRESHOLD_METERS) {
            return [new Alert(
                type: AlertType::WARNING,
                message: \sprintf(
                    'Écart de %dm entre étape %d et %d.',
                    (int) $gapMeters,
                    $stage->dayNumber,
                    $nextStage->dayNumber,
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
