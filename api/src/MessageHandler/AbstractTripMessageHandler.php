<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

abstract readonly class AbstractTripMessageHandler
{
    private TripCompletionGate $completionGate;

    public function __construct(
        protected ComputationTrackerInterface $computationTracker,
        protected TripUpdatePublisherInterface $publisher,
        protected TripGenerationTrackerInterface $generationTracker,
        protected LoggerInterface $logger,
        protected TripRequestRepositoryInterface $tripRequestRepository,
        protected MessageBusInterface $messageBus,
        protected AlertRenderer $alertRenderer,
    ) {
        // The gate is a stateless collaborator built from dependencies the handler
        // already receives, so we construct it here rather than threading the
        // service through the ~20 child constructors or relying on a #[Required]
        // setter (which manual instantiations — e.g. integration tests — skip,
        // leaving the readonly property uninitialized). The terminal-gate logic
        // stays single-sourced in TripCompletionGate.
        $this->completionGate = new TripCompletionGate($this->computationTracker, $this->publisher, $this->messageBus, $this->generationTracker);
    }

    /**
     * Turns the flat alert list a producer publishes into the per-stage map it persists.
     *
     * The same arrays, regrouped — never rebuilt — so the database and the wire cannot drift
     * (ADR-068). Two fields are dropped on the way in: `stageId`, which becomes the key, and
     * `dayNumber`, which every structural edit renumbers and which is therefore derived from
     * the owning stage at read time rather than frozen here (ADR-066).
     *
     * @param list<array<string, mixed>> $alerts
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function groupByStage(array $alerts): array
    {
        $grouped = [];
        foreach ($alerts as $alert) {
            $stageId = $alert['stageId'] ?? null;
            if (!\is_string($stageId)) {
                continue;
            }

            unset($alert['stageId'], $alert['dayNumber']);
            $grouped[$stageId][] = $alert;
        }

        return $grouped;
    }

    /**
     * Renders a flat published alert list in the trip's language.
     *
     * The trip locale and not the reader's, because on this channel there is only one
     * reader: an anonymous visitor receives no SSE, so the audience is the owner whose
     * account locale the trip carries (ADR-069). A GET renders the same rows again, for
     * whoever asked.
     *
     * @param list<array<string, mixed>> $alerts
     *
     * @return list<array<string, mixed>>
     */
    protected function renderForWire(string $tripId, array $alerts): array
    {
        return $this->alertRenderer->renderFlat($alerts, $this->tripRequestRepository->getLocale($tripId) ?? 'en');
    }

    /**
     * Executes the handler body with computation tracking.
     * Marks computation as running, executes callback, then marks done.
     *
     * No staleness check: a message the trip has moved past never reaches a handler at all
     * since {@see \App\Messenger\StaleMessageMiddleware} (ADR-073). The check used to live
     * here, and in two other places that had begun to differ.
     *
     * On exception: publishes the error event and re-throws so Messenger can
     * retry. The computation is intentionally NOT marked `failed` here — that
     * would happen on every failed attempt, including ones a retry could still
     * recover, and would let the terminal gate fire prematurely. The terminal
     * `failed` status (and the gate re-evaluation) is set only once the retries
     * are exhausted, by {@see \App\EventListener\ComputationFailureSubscriber}.
     *
     * @throws \Throwable
     */
    protected function executeWithTracking(
        string $tripId,
        ComputationName $computation,
        callable $callback,
    ): void {
        $this->computationTracker->markRunning($tripId, $computation);

        $startTime = hrtime(true);

        try {
            $callback();
            $duration = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->computationTracker->markDone($tripId, $computation);
            $this->logger->info('Handler {name} completed in {duration}ms.', [
                'name' => $computation->value,
                'duration' => $duration,
                'tripId' => $tripId,
            ]);
        } catch (\Throwable $throwable) {
            $duration = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->publisher->publishComputationError($tripId, $computation->value, $throwable->getMessage());
            $this->logger->warning('Handler {name} failed after {duration}ms.', [
                'name' => $computation->value,
                'duration' => $duration,
                'tripId' => $tripId,
            ]);

            throw $throwable;
        }

        // Mode 1 — progress bar: publish a business-data-free progress event
        // after every handler completes, so the frontend can drive its narrative stepper.
        $this->publishProgress($tripId, $computation);

        // Issue #299 — gate: when every initialized enrichment has settled
        // (done OR failed), publish the terminal TRIP_COMPLETE event and dispatch
        // AllEnrichmentsCompleted. Shared with ComputationFailureSubscriber so the
        // happy path and the retries-exhausted path evaluate the same condition.
        $this->completionGate->evaluate($tripId);
    }

    /**
     * Publishes the computation_step_completed progress event and returns the progress snapshot.
     *
     * Counts are derived from the {@see ComputationTrackerInterface::getProgress()}
     * helper so a single handler failure does not stall the progress bar
     * (failed statuses still count toward the total settled steps).
     *
     * @return array{completed: int, failed: int, settled: int, total: int}
     */
    private function publishProgress(string $tripId, ComputationName $step): array
    {
        $progress = $this->computationTracker->getProgress($tripId);

        if (0 !== $progress['total']) {
            $this->publisher->publishComputationStepCompleted(
                $tripId,
                $step,
                $progress['completed'],
                $progress['total'],
                $progress['failed'],
            );
        }

        return $progress;
    }
}
