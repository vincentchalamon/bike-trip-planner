<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a single user modification in a batch recompute request.
 *
 * Each modification describes which stage is affected and what kind of change
 * was made. The {@see \App\Service\ComputationDependencyResolver} uses this
 * information to determine which computations need to be re-run.
 */
final class TripModification
{
    public function __construct(
        /**
         * Zero-based index of the affected stage.
         * Null for trip-level modifications (dates, pacing settings, etc.).
         */
        #[Assert\PositiveOrZero]
        public ?int $stageIndex = null,

        /**
         * Type of modification — determines which handlers are re-dispatched.
         *
         * Valid values: 'accommodation', 'distance', 'dates', 'pacing'.
         */
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['accommodation', 'distance', 'dates', 'pacing'])]
        public string $type = 'accommodation',

        /**
         * Human-readable description for display in the frontend queue panel.
         */
        #[Assert\Length(max: 255)]
        public ?string $label = null,
    ) {
    }
}
