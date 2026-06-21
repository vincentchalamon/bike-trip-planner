<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\TripStatus;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\StructuralComputationService;
use App\Service\TripAnalysisDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class GenerateStagesHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private StructuralComputationService $structuralComputation,
        private TripAnalysisDispatcher $analysisDispatcher,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(GenerateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripStateManager->getRequest($tripId);

        if (!$request instanceof TripRequest) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::STAGES, function () use ($tripId, $request, $generation): void {
            $stages = $this->structuralComputation->generateStages($tripId, $request);

            if (\count($stages) < TripStatus::MIN_STAGES) {
                $this->publisher->publishValidationError($tripId, 'MIN_STAGES', 'A minimum of 2 stages is required.');
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            // ADR-043: structural readiness is reached as soon as the stages are
            // persisted — independently of the terminal enrichment gate, so a trip
            // without dates (weather/calendar never settle) still becomes `ready`.
            if (\count($stages) >= TripStatus::MIN_STAGES) {
                $this->tripStateManager->storeStatus($tripId, TripStatus::READY->value);
            }

            $this->publisher->publish(
                $tripId,
                MercureEventType::STAGES_COMPUTED,
                ['stages' => $this->structuralComputation->serializeStagesForEvent($stages)],
            );

            $this->analysisDispatcher->dispatch($tripId, $request, $generation);
        }, $generation);
    }
}
