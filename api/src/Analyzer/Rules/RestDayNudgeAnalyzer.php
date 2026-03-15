<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Suggests a rest day after every N consecutive cycling days (default: 3).
 *
 * This analyzer is context-aware: it reads the full stage list to detect how
 * many consecutive non-rest-day stages precede the current one.
 */
final readonly class RestDayNudgeAnalyzer implements StageAnalyzerInterface
{
    private const int DEFAULT_CONSECUTIVE_DAYS_THRESHOLD = 3;

    public function __construct(
        private TranslatorInterface $translator,
        private int $consecutiveDaysThreshold = self::DEFAULT_CONSECUTIVE_DAYS_THRESHOLD,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        // Rest days themselves don't need this alert
        if ($stage->isRestDay) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        /** @var list<Stage> $allStages */
        $allStages = $context['allStages'] ?? [];

        if ([] === $allStages) {
            return [];
        }

        // Find position of this stage in the full stage list
        $stageIndex = null;
        foreach ($allStages as $i => $s) {
            if ($s->dayNumber === $stage->dayNumber) {
                $stageIndex = $i;
                break;
            }
        }

        if (null === $stageIndex) {
            return [];
        }

        // Count consecutive non-rest-day stages ending at this stage
        $consecutiveCount = 0;
        for ($i = $stageIndex; $i >= 0; --$i) {
            if ($allStages[$i]->isRestDay) {
                break;
            }

            ++$consecutiveCount;
        }

        if ($consecutiveCount < $this->consecutiveDaysThreshold) {
            return [];
        }

        // Only emit the nudge exactly on the Nth day (not every day after)
        if (0 !== $consecutiveCount % $this->consecutiveDaysThreshold) {
            return [];
        }

        return [new Alert(
            type: AlertType::NUDGE,
            message: $this->translator->trans(
                'alert.rest_day.nudge',
                [
                    '%stage%' => $stage->dayNumber,
                    '%days%' => $consecutiveCount,
                ],
                'alerts',
                $locale,
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 100;
    }
}
