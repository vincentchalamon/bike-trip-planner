<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for the batch recompute endpoint.
 *
 * Carries a list of pending modifications as described by the frontend batch queue.
 * The backend resolves the minimal set of handlers to re-run and dispatches them.
 */
final class TripBatchRecomputeRequest
{
    /**
     * @param list<TripModification> $modifications
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Count(min: 1)]
        public array $modifications = [],
    ) {
    }
}
