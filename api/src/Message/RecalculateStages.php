<?php

declare(strict_types=1);

namespace App\Message;

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
     * @param list<string> $affectedStageIds
     * @param bool         $skipAccommodationScan skip only the accommodation scan (POIs, bike shops and terrain still run)
     * @param bool         $skipGeographicScans   Skip ALL geographic scans (POIs, accommodations, bike shops, terrain).
     *                                            Takes precedence over $skipAccommodationScan: when true,
     *                                            $skipAccommodationScan is irrelevant.
     */
    public function __construct(
        public string $tripId,
        public array $affectedStageIds,
        public bool $skipAccommodationScan = false,
        public bool $skipGeographicScans = false,
        public ?int $generation = null,
    ) {
    }
}
