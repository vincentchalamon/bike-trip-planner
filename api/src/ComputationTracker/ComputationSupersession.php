<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Service\TripCompletionGate;
use Psr\Log\LoggerInterface;

/**
 * Settles the computations a generation bump left behind, and says so once (ADR-073).
 *
 * A bump invalidates every message in flight. The caller then re-dispatches what the edit
 * actually invalidated — the trigger union for a structural edit, the resolver's subset for a
 * `PATCH`. Whatever was in flight and is *not* in that set is never coming back: its message
 * will be discarded by {@see \App\Messenger\StaleMessageMiddleware} and nothing will replace
 * it. Left alone, those entries stay `pending` for good, and
 * {@see TripCompletionGate} can then never close again — no `trip_complete`, no `trip_ready`,
 * a loader spinning on both clients.
 *
 * **This runs at the bump, never at the consume.** The status map is keyed by computation, not
 * by (computation, generation), so a worker arriving late from the old generation cannot
 * safely write to it: by then the map describes the new one. It would overwrite a `done` the
 * newer generation had already recorded, or — worse — mark a computation terminal while that
 * generation's work was still queued, closing the gate early. The caller here has neither
 * problem: it runs before the new work can finish, and it knows exactly what it dispatched.
 */
final readonly class ComputationSupersession
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripUpdatePublisherInterface $publisher,
        private TripCompletionGate $completionGate,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ComputationName> $dispatched what the new generation is re-running, and which
     *                                          therefore is not superseded
     */
    public function settleWhatWasNotRedispatched(string $tripId, array $dispatched): void
    {
        $statuses = $this->computationTracker->getStatuses($tripId);
        if (null === $statuses) {
            return;
        }

        $superseded = [];
        foreach (array_keys($statuses) as $name) {
            $computation = ComputationName::tryFrom($name);
            if (!$computation instanceof ComputationName || \in_array($computation, $dispatched, true)) {
                continue;
            }

            if ($this->computationTracker->markSupersededUnlessSettled($tripId, $computation)) {
                $superseded[] = $computation;
            }
        }

        if ([] === $superseded) {
            return;
        }

        // Same cascade, and the same reason, as ComputationFailureSubscriber: wind and fords
        // are dispatched only at the end of FetchWeatherHandler. If the weather never runs,
        // they are never dispatched either and would hold the total out of reach.
        if (\in_array(ComputationName::WEATHER, $superseded, true)) {
            foreach ([ComputationName::WIND, ComputationName::FORDS] as $cascaded) {
                if ($this->computationTracker->markSupersededUnlessSettled($tripId, $cascaded)) {
                    $superseded[] = $cascaded;
                }
            }
        }

        $this->logger->info('Computations superseded by a newer generation of trip {tripId}.', [
            'tripId' => $tripId,
            'computations' => array_map(static fn (ComputationName $c): string => $c->value, $superseded),
        ]);

        // Once, with the list — not once per computation. A client only needs to learn that
        // this much was abandoned, and the envelope already carries the version that
        // superseded them.
        $this->publisher->publishComputationsSuperseded($tripId, $superseded);

        $this->completionGate->evaluate($tripId);
    }
}
