<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\ApiResource\Stage;
use App\ApiResource\TripRequest;

/**
 * What a stage analyzer may read beyond the stage itself.
 *
 * Typed rather than a string-keyed array: each key used to be read with its own `?? default`,
 * so a renamed key silently fell back to the default (the sunset alert once dated every stage
 * from the trip start that way, #1290) and two keys nobody read were still being built.
 */
final readonly class StageAnalysisContext
{
    /**
     * @param list<Stage>                $allStages every stage of the trip, in order
     * @param list<array<string, mixed>> $osmWays   the OSM ways along this stage, as the terrain index returns them
     */
    public function __construct(
        public ?Stage $nextStage = null,
        public array $allStages = [],
        public bool $ebikeMode = false,
        public array $osmWays = [],
        public ?\DateTimeImmutable $startDate = null,
        public int $departureHour = TripRequest::DEFAULT_DEPARTURE_HOUR,
        public float $averageSpeed = TripRequest::DEFAULT_AVERAGE_SPEED,
    ) {
    }
}
