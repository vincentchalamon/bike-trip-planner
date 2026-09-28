<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\Service\TripAnalysisDispatcher;
use App\Service\TripBootstrapper;
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
        TripRequestRepositoryInterface $tripRequestRepository,
        TripStageStoreInterface $stageStore,
        private TripBootstrapper $bootstrapper,
        private TripAnalysisDispatcher $analysisDispatcher,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripRequestRepository, $stageStore, $messageBus, $alertRenderer);
    }

    public function __invoke(GenerateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripRequestRepository->getRequest($tripId);

        if (!$request instanceof TripRequest) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::STAGES, function () use ($tripId, $request, $generation): void {
            $this->bootstrapper->storeStages($tripId, $request);

            $this->analysisDispatcher->dispatch($tripId, $request, $generation);
        });
    }
}
