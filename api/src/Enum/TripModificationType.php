<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a queued modification changed, and so which computations a batch recompute re-runs
 * ({@see \App\Service\ModificationMessageResolver}).
 */
enum TripModificationType: string
{
    case ACCOMMODATION = 'accommodation';
    case DISTANCE = 'distance';
    case DATES = 'dates';
    case PACING = 'pacing';

    /**
     * Stage-level modifications name the stage they apply to; the others are trip-wide.
     */
    public function targetsStage(): bool
    {
        return self::ACCOMMODATION === $this || self::DISTANCE === $this;
    }
}
