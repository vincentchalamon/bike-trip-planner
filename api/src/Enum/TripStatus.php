<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Persisted structural-readiness status of a trip (ADR-043).
 *
 * Reflects whether the structural computation (route + pacing stages) has settled,
 * independently of the asynchronous per-block enrichments (weather, AI, …) which
 * are tracked separately by the {@see \App\ComputationTracker\ComputationTrackerInterface}.
 *
 *  - `draft`: created, stages not yet generated.
 *  - `ready`: stages persisted (>= {@see self::MIN_STAGES}); the trip is renderable.
 */
enum TripStatus: string
{
    case DRAFT = 'draft';
    case READY = 'ready';

    /**
     * Minimum number of stages a trip must have to be considered structurally valid
     * (mirrors the MIN_STAGES validation in the stage-generation pipeline).
     */
    public const int MIN_STAGES = 2;
}
