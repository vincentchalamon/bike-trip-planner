<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\ComputationName;

/**
 * A message whose handling is one tracked computation.
 *
 * Declared on the message so the mapping exists once: the handler marks it running and done,
 * {@see \App\EventListener\ComputationFailureSubscriber} marks it failed once the retries are
 * exhausted, {@see \App\Messenger\RearmDispatchedComputationMiddleware} re-arms it on dispatch,
 * and {@see \App\Service\EnrichmentMessageFactory} finds the message that carries it. Each of
 * them used to hold its own table.
 */
interface TracksComputation extends BelongsToATripGeneration
{
    public static function computation(): ComputationName;
}
