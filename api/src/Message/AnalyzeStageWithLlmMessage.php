<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched (one per stage) by {@see \App\MessageHandler\AllEnrichmentsCompletedHandler}
 * once every enrichment has settled, to trigger the LLaMA 8B pass-1 stage analysis
 * (issue #301).
 *
 * Each message is independent and parallelisable across the Messenger workers; the
 * handler reads the enriched stage from the repository, builds a compact JSON summary
 * (~200-300 tokens) and persists the LLM response on the Stage entity.
 *
 * `dayNumber` matches {@see \App\ApiResource\Stage::$dayNumber} (1-indexed).
 */
final readonly class AnalyzeStageWithLlmMessage
{
    public function __construct(
        public string $tripId,
        public int $dayNumber,
    ) {
    }
}
