<?php

declare(strict_types=1);

namespace App\Messenger;

use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\BelongsToATripGeneration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Drops a message the trip has moved past, before the handler is built (ADR-073).
 *
 * The comparison itself is old; where it lived was the problem. It sat in three places —
 * `AbstractTripMessageHandler::isStale()` behind `executeWithTracking()`, a direct call in
 * `RecalculateStagesHandler`, and a hand-rewritten copy in `ResolveStageLabelsHandler` — which
 * is the same shape ADR-070 found behind four drifted dispatch tables. One of the three had
 * already stopped being the same comparison.
 *
 * Short-circuiting here rather than inside the handler also means the handler and its
 * dependencies are never built: this middleware runs ahead of `HandleMessageMiddleware`, which
 * is what resolves them. That is a real saving but a modest one — the transport has already
 * deserialized the message by the time any middleware runs. The reason to be here is that
 * there is one place to read.
 *
 * **It writes nothing and publishes nothing.** The status map is keyed by computation, not by
 * (computation, generation), so a worker arriving late from generation N would be writing into
 * a map that now describes N+1 — over a `done` the newer generation had already recorded, and
 * possibly closing the completion gate while that generation's work was still queued. Settling
 * a superseded computation therefore belongs to whoever moved the generation and knows what it
 * re-dispatched, in {@see \App\ComputationTracker\ComputationSupersession}.
 */
final readonly class StaleMessageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TripGenerationTrackerInterface $generationTracker,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof BelongsToATripGeneration) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Dispatch side: the bus runs this middleware there too, and a message is never stale
        // at the moment it is sent. Same guard as HandleCorrelationIdMiddleware.
        if (!$envelope->last(ConsumedByWorkerStamp::class) instanceof StampInterface) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!$this->isStale($message)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->logger->info('Discarding a message the trip has moved past.', [
            'tripId' => $message->tripId,
            'message' => $message::class,
            'messageGeneration' => $message->generation,
            'currentGeneration' => $this->generationTracker->current($message->tripId),
        ]);

        // Returning without calling $stack->next() is what stops the chain: Messenger sees a
        // handled message and acks it. Not an exception — a superseded message is not a
        // failure, and throwing would send it to the retry strategy and then to `failed`.
        return $envelope;
    }

    /**
     * A generation strictly below the current one is stale.
     *
     * Kept exactly as it was, including the two ways of answering "not stale": a message that
     * carries no generation (its sender did not scope it) and a trip that has none (it does not
     * exist). And including the strict comparison, which lets a generation *above* the current
     * one through — three processors read `current()` outside the write lock, so tightening it
     * is a separate change (ADR-073).
     */
    private function isStale(BelongsToATripGeneration $message): bool
    {
        if (null === $message->generation) {
            return false;
        }

        $current = $this->generationTracker->current($message->tripId);

        return null !== $current && $message->generation < $current;
    }
}
