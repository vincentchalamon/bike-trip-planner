<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class EbikeRangeAnalyzer implements StageAnalyzerInterface
{
    private const float BASE_RANGE_KM = 80.0;

    private const float ELEVATION_PENALTY_DIVISOR = 25.0;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        if (true !== ($context['ebikeMode'] ?? false)) {
            return [];
        }

        $effectiveRange = max(0.0, self::BASE_RANGE_KM - ($stage->elevation / self::ELEVATION_PENALTY_DIVISOR));

        if ($stage->distance <= $effectiveRange) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return [new Alert(
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.ebike_range.warning',
                [
                    '%stage%' => $stage->dayNumber,
                    '%distance%' => (int) round($stage->distance),
                    '%range%' => (int) round($effectiveRange),
                ],
                'alerts',
                $locale,
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }
}
