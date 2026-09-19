<?php

declare(strict_types=1);

namespace App\Concurrency;

use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Compares the version a client pinned with `If-Match` against the persisted one.
 *
 * Called from inside the write's critical section, never before it. A check performed
 * earlier — in a processor decorator, say — leaves a window as wide as the processor body:
 * {@see \App\State\StageAddManualAccommodationProcessor} geocodes an address before writing,
 * so two requests could both pass a check against version N and then write in turn, which is
 * the very lost update the precondition exists to prevent.
 */
final readonly class VersionPrecondition
{
    /**
     * @param int|null $expected what the client pinned, null when it pinned nothing
     * @param int|null $actual   the persisted version, null when the trip is gone
     *
     * @throws PreconditionFailedHttpException when the two differ
     */
    public static function assert(?int $expected, ?int $actual, string $tripId): void
    {
        if (null === $expected || $expected === $actual) {
            return;
        }

        throw new PreconditionFailedHttpException(\sprintf('Trip %s has moved on: you edited version %d, the current one is %s. Reload it and reapply your change.', $tripId, $expected, $actual ?? 'gone'));
    }
}
