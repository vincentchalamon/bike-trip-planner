<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\Stage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the stage identifier an operation was addressed with to its current position.
 *
 * The URL names a stage by identity (ADR-066), but the editing rules are positional by
 * nature — inserting *after* a stage, shifting the *next* stage's start point, renumbering
 * the days that *follow*. So the identifier is resolved to a position, and the resolution
 * happens inside the locked read-modify-write, against the list that is actually about to
 * be written rather than one read earlier.
 *
 * An identifier that no longer resolves is a 404, which is the intended answer after a
 * pacing regeneration: those are not the same stages any more.
 */
final class StageLocator
{
    /**
     * @param list<Stage> $stages
     */
    public function indexOf(array $stages, string $stageId): int
    {
        foreach ($stages as $index => $stage) {
            if ($stage->id === $stageId) {
                return $index;
            }
        }

        throw new NotFoundHttpException(\sprintf('Stage "%s" not found.', $stageId));
    }

    /**
     * @param list<Stage> $stages
     */
    public function find(array $stages, string $stageId): ?Stage
    {
        foreach ($stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }

        return null;
    }
}
