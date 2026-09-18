<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Stage;

/**
 * What a stage write produced: the stages as written, and the structural version that write
 * resulted in.
 *
 * The version has to come back from inside the critical section. Read afterwards, it could
 * belong to somebody else's write — a concurrent edit or a worker bumping the version in the
 * window between the lock being released and the caller reading it. The caller would then
 * stamp its messages with a later, unrelated generation, and `isStale()` (which only rejects
 * `messageGeneration < current`) would never reject them, even though they were built from
 * older data. That is precisely the "the staleness guard silently stops protecting anything"
 * failure ADR-066 exists to close.
 */
final readonly class StageWriteResult
{
    /**
     * @param list<Stage> $stages
     */
    public function __construct(
        public array $stages,
        public int $version,
    ) {
    }
}
