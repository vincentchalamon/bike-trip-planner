<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\ApiResource\Stage;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Wires a mocked repository so `mutateStages()` behaves as its implementations do: read,
 * apply, write.
 *
 * The processors no longer call `getStages()` and `storeStages()` themselves — the pair
 * has to happen inside one critical section, so it moved behind `mutateStages()`. Routing
 * the stub through the same two methods keeps every existing `getStages()` return value
 * and `storeStages()` expectation meaningful, and keeps these tests about what the
 * processor computes rather than about how the write is serialised (that is covered by
 * the repository's own tests).
 */
trait MutateStagesStubTrait
{
    private function stubMutateStages(TripRequestRepositoryInterface&Stub $repository): void
    {
        $repository->method('mutateStages')->willReturnCallback(
            /**
             * @param callable(list<Stage>): list<Stage> $mutator
             *
             * @return list<Stage>|null
             */
            static function (string $tripId, callable $mutator) use ($repository): ?array {
                $stages = $repository->getStages($tripId);
                if (null === $stages) {
                    return null;
                }

                $mutated = $mutator($stages);
                $repository->storeStages($tripId, $mutated);

                return $mutated;
            },
        );
    }
}
