<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\ComputationTrigger;

final readonly class RecalculateStages
{
    /**
     * Recomputes the stages affected by an edit.
     *
     * Carries identifiers rather than positions, and they are resolved to positions when
     * the message is consumed: the handler then acts on the stages the sender meant even
     * if they have moved, and simply skips the ones that no longer exist, instead of the
     * whole message acting on the wrong ones.
     *
     * An empty list means "all stages" — deliberately kept as such rather than expanded
     * to identifiers at send time, which would miss stages created in between.
     *
     * @param list<string>             $affectedStageIds
     * @param list<ComputationTrigger> $triggers              What this edit invalidated, so the
     *                                                        handler re-runs the union once
     *                                                        (ADR-070). A rest-day edit passes
     *                                                        DATES alone: it shifts every later
     *                                                        stage's date without moving a metre
     *                                                        of line. Empty means the stages are
     *                                                        recomputed and nothing is enriched.
     * @param bool                     $skipAccommodationScan held back even when the triggers
     *                                                        cover it: an accommodation edit
     *                                                        moves the next stage's start point,
     *                                                        but re-scanning would overwrite the
     *                                                        choice the rider just made
     */
    public function __construct(
        public string $tripId,
        public array $affectedStageIds,
        public bool $skipAccommodationScan = false,
        public array $triggers = [ComputationTrigger::GEOMETRY],
        public ?int $generation = null,
    ) {
    }
}
