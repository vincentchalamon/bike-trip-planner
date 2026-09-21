<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\ComputationTrigger;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripAnalysisDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RecalculateStagesHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
        private TripAnalysisDispatcher $analysisDispatcher,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus, $alertRenderer);
    }

    public function __invoke(RecalculateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        if ($this->isStale($tripId, $generation)) {
            $this->logger->info('Discarding stale RecalculateStages message.', [
                'tripId' => $tripId,
                'messageGeneration' => $generation,
                'currentGeneration' => $this->generationTracker->current($tripId),
            ]);

            return;
        }

        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        // Resolve identifiers to the stages as they stand now, so an edit that happened
        // since this message was sent moves the target rather than mis-targeting it. A
        // stage that no longer exists is simply skipped.
        $byId = [];
        foreach ($stages as $stage) {
            $byId[$stage->id] = $stage;
        }

        // If empty, recalculate all stages (e.g. after a move)
        $affected = [] === $message->affectedStageIds
            ? $stages
            : array_values(array_filter(array_map(
                static fn (string $stageId): ?Stage => $byId[$stageId] ?? null,
                $message->affectedStageIds,
            )));

        // Mode 2 — inline modification (Act 3): emit one `stage_updated` event per
        // affected stage so the frontend mutates the corresponding slice of its
        // store without rebuilding the whole trip. The per-stage events carry the
        // authoritative stored values (including a user-requested distance honored
        // by StageUpdateProcessor::applyDistanceChange). We intentionally do NOT
        // also publish the legacy wholesale `STAGES_COMPUTED` here: its partial-merge
        // branch on the frontend re-hydrated the edited stage from the wire payload,
        // racing the `stage_updated` slice and reverting a user-set distance
        // (e.g. 80km -> 60km snapping back). The initial generation path still emits
        // `STAGES_COMPUTED` (GenerateStagesHandler / GpxUploadService) (issue #774).
        // The position comes from the list as it stands now, so the client can tell an
        // appended stage from an event of a superseded generation.
        $positions = array_flip(array_map(static fn (Stage $s): string => $s->id, $stages));
        foreach ($affected as $stage) {
            $this->publisher->publishStageUpdated($tripId, $stage, $positions[$stage->id]);
        }

        // The line moved, so everything drawn from it is now wrong. Which computations those
        // are is declared on ComputationName::triggers(), not listed here: this method used
        // to name five of the twelve, which is how a merge left seven groups holding alerts
        // computed against the pre-merge line (ADR-070).
        if ([] !== $affected && !$message->skipGeographicScans) {
            $request = $this->tripStateManager->getRequest($tripId);
            \assert($request instanceof TripRequest);

            $this->analysisDispatcher->dispatchFor(
                $tripId,
                $request,
                [ComputationTrigger::GEOMETRY],
                $generation,
                // Accommodation scans hit an external source per stage, so they stay scoped
                // to the stages this edit touched; every other computation is trip-wide.
                scopedStageIds: array_map(static fn (Stage $stage): string => $stage->id, $affected),
                // An accommodation edit moves the next stage's start point, so the geometry
                // set is right — but re-scanning would overwrite the choice just made.
                except: $message->skipAccommodationScan ? [ComputationName::ACCOMMODATIONS] : [],
            );
        }
    }
}
