<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RecalculateStages
{
    /**
     * @param list<int> $affectedIndices
     * @param bool      $skipAccommodationScan skip only the accommodation scan (POIs, bike shops and terrain still run)
     * @param bool      $skipGeographicScans   Skip ALL geographic scans (POIs, accommodations, bike shops, terrain).
     *                                         Takes precedence over $skipAccommodationScan: when true,
     *                                         $skipAccommodationScan is irrelevant.
     * @param bool      $skipAiAnalysis        When true, cascading messages dispatched by the handler inherit
     *                                         `skipAiAnalysis = true` so the LLaMA 8B overview pass is not
     *                                         re-triggered by an inline modification (Mode 2). Defaults to true
     *                                         for all recomputations since Mode 2 already issues STAGE_UPDATED
     *                                         events instead of the full TRIP_READY cycle.
     */
    public function __construct(
        public string $tripId,
        public array $affectedIndices,
        public bool $skipAccommodationScan = false,
        public bool $skipGeographicScans = false,
        public ?int $generation = null,
        public bool $skipAiAnalysis = true,
    ) {
    }
}
