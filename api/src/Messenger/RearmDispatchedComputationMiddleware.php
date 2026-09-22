<?php

declare(strict_types=1);

namespace App\Messenger;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\EventListener\ComputationFailureSubscriber;
use App\Message\BelongsToATripGeneration;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Keeps one invariant: a computation with a message in flight never reads as terminal
 * (ADR-073).
 *
 * `ComputationSupersession` marks as `superseded` whatever its caller did not re-dispatch. That
 * is only true if the caller's dispatch is the whole story, and often it is not — dispatching
 * `GenerateStages` re-runs the entire enrichment pipeline from inside
 * `GenerateStagesHandler`, and a successful weather fetch cascades into wind and fords. A
 * `PATCH` on `fatigueFactor` re-dispatches `STAGES` alone, so every enrichment was marked
 * superseded; the fan-out that follows would then find them terminal, and
 * `TripCompletionGate` would settle — publishing `trip_ready` for a generation whose
 * enrichments had not started, and burning the one-shot publication claim so the real one was
 * dropped as a duplicate.
 *
 * Teaching the settler about those cascades would mean a second copy of knowledge the handlers
 * already hold — the shape ADR-070 spent an ADR removing. Stating the invariant here instead
 * costs nothing per cascade and covers the ones not written yet: **every** dispatch passes
 * through the bus.
 *
 * Send side only, and the counterpart to {@see StaleMessageMiddleware}, which acts on the
 * handle side and writes nothing.
 */
final readonly class RearmDispatchedComputationMiddleware implements MiddlewareInterface
{
    public function __construct(private ComputationTrackerInterface $computationTracker)
    {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        // Consuming a message is not dispatching one: the handler arms its own computation
        // through `executeWithTracking()`, and re-arming here would undo a `markRunning`.
        if (!$message instanceof BelongsToATripGeneration
            || $envelope->last(ConsumedByWorkerStamp::class) instanceof StampInterface) {
            return $stack->next()->handle($envelope, $stack);
        }

        // The same table `ComputationFailureSubscriber` resolves failures through, guarded in
        // CI against `ComputationName::pipeline()`. A message absent from it is not a tracked
        // computation and has no status to arm.
        $computation = ComputationFailureSubscriber::MESSAGE_TO_COMPUTATION[$message::class] ?? null;
        if ($computation instanceof ComputationName) {
            $this->computationTracker->rearmIfSettled($message->tripId, $computation);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
