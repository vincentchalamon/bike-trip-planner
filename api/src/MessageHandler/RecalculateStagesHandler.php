<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Llm\LlmAnalysisTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
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
        private LlmAnalysisTrackerInterface $llmTracker,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
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

        // Inline edits (issue #311): when the caller signals "skip AI re-analysis",
        // mark the trip so the downstream gate handler skips dispatching the
        // LLaMA 8B passes. The marker is consumed (one-shot) by the gate handler.
        // Set after the stages guard so a dangling marker can't leak to an
        // unrelated AllEnrichmentsCompleted cycle if stage data is missing.
        if ($message->skipAiAnalysis) {
            $this->llmTracker->markSkipAiAnalysis($tripId);
        }

        $affectedIndices = $message->affectedIndices;
        // If empty, recalculate all stages (e.g. after a move)
        if ([] === $affectedIndices) {
            $affectedIndices = array_keys($stages);
        }

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
        foreach ($affectedIndices as $idx) {
            if (isset($stages[$idx])) {
                $this->publisher->publishStageUpdated($tripId, $stages[$idx]);
            }
        }

        // Dispatch POI/Accommodation/BikeShop scans for affected stages
        if ([] !== $affectedIndices && !$message->skipGeographicScans) {
            $this->messageBus->dispatch(new ScanPois($tripId, $generation));
            if (!$message->skipAccommodationScan) {
                $request = $this->tripStateManager->getRequest($tripId);
                \assert($request instanceof TripRequest);
                foreach ($affectedIndices as $idx) {
                    $this->messageBus->dispatch(new ScanAccommodations(
                        $tripId,
                        stageIndex: $idx,
                        enabledAccommodationTypes: $request->enabledAccommodationTypes,
                        generation: $generation,
                    ));
                }
            }

            $this->messageBus->dispatch(new CheckBikeShops($tripId, $generation));
            $this->messageBus->dispatch(new AnalyzeTerrain($tripId, $generation));
            $this->messageBus->dispatch(new ScanEvents($tripId, $generation));
        }
    }
}
