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
        private int $consecutiveDaysThreshold = self::DEFAULT_CONSECUTIVE_DAYS_THRESHOLD,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        // Rest days themselves don't need this alert
        if ($stage->isRestDay) {
            return [];
        }

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

        // No point suggesting a rest day on the very last stage of the trip
        if ($stageIndex === \count($allStages) - 1) {
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

        // The rider already rests the day right after this block: the "consider a
        // rest day" nudge is moot, so suppress it. Removing that rest day makes
        // this analyzer emit again (the nudge is restored) — recette.
        $nextStage = $allStages[$stageIndex + 1] ?? null;
        if ($nextStage instanceof Stage && $nextStage->isRestDay) {
            return [];
        }

        return [new Alert(
            code: AlertCode::REST_DAY_SUGGESTED,
            type: AlertType::NUDGE,
            messageKey: 'alert.rest_day.nudge',
            parameters: ['%days%' => $consecutiveCount],
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                labelKey: 'alert.rest_day.action',
                payload: ['afterStage' => $stage->dayNumber],
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 100;
    }
}
