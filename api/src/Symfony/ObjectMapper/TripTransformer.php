<?php

declare(strict_types=1);

namespace App\Symfony\ObjectMapper;

use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\Trip;
use App\ComputationTracker\ComputationTrackerInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * @implements TransformCallableInterface<Stage, StageResponse>
 */
final readonly class TripTransformer implements TransformCallableInterface
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
    ) {
    }

    /**
     * @param string             $value
     * @param Stage              $source
     * @param StageResponse|null $target
     */
    public function __invoke(mixed $value, object $source, ?object $target): Trip
    {
        return new Trip(
            id: $value,
            computationStatus: $this->computationTracker->getStatuses($value) ?? [],
        );
    }
}
