<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched once every per-stage LLaMA 8B pass-1 analysis has settled, to trigger
 * the LLaMA 8B pass-2 trip overview synthesis (issue #302).
 *
 * Pass 2 takes the per-stage briefings produced by {@see AnalyzeStageWithLlmMessage}
 * plus the rider profile and synthesises a global narrative covering cumulative
 * fatigue, cross-stage patterns and trip-level recommendations. The handler runs
 * once per trip — there is no parallel decomposition.
 *
 * The orchestration of the dispatch (gate detection, automatic chaining
 * post-pass-1) is the responsibility of issue #303; this message remains a plain
 * envelope carrying only the trip identifier.
 */
final readonly class AnalyzeTripOverviewWithLlmMessage
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
