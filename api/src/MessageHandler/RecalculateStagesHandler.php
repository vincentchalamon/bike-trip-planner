<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationSupersession;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
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
        private ComputationSupersession $supersession,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus, $alertRenderer);
    }

    public function __invoke(RecalculateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

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

        // What the edit invalidated travels on the message, and the union is dispatched
        // here, once. This method used to name five computations out of twelve — which is
        // how a merge left seven groups holding alerts drawn from the pre-merge line — and
        // the senders made up the difference themselves, dispatching the overlap twice
        // (ADR-070).
        $dispatched = [];
        if ([] !== $affected && [] !== $message->triggers) {
            $request = $this->tripStateManager->getRequest($tripId);
            \assert($request instanceof TripRequest);

            $dispatched = $this->analysisDispatcher->dispatchFor(
                $tripId,
                $request,
                $message->triggers,
                $generation,
                // Accommodation scans hit an external source per stage, so they stay scoped
                // to the stages this edit touched; every other computation is trip-wide.
                scopedStageIds: array_map(static fn (Stage $stage): string => $stage->id, $affected),
                // An accommodation edit moves the next stage's start point, so the geometry
                // set is right — but re-scanning would overwrite the choice just made.
                except: $message->skipAccommodationScan ? [ComputationName::ACCOMMODATIONS] : [],
            );
        }

        // The seven structural edits all bump the generation inside `mutateStages()` and all
        // funnel here, so this is where what they stranded gets settled: everything that was
        // still in flight and is not in the set just re-dispatched (ADR-073).
        $this->supersession->settleWhatWasNotRedispatched($tripId, $dispatched);
    }
}
