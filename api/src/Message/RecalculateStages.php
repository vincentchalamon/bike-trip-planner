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
     * @param bool      $skipAiAnalysis        Skip the LLaMA 8B pass-1 / pass-2 re-analysis at the end of the
     *                                         enrichment chain. Used by chat-driven inline edits so the rider can
     *                                         iterate quickly without re-paying the LLM cost on every tweak.
     */
    public function __construct(
        public string $tripId,
        public array $affectedIndices,
        public bool $skipAccommodationScan = false,
        public bool $skipGeographicScans = false,
        public ?int $generation = null,
        public bool $skipAiAnalysis = false,
    ) {
    }
}
