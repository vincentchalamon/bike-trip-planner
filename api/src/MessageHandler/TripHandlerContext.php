<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The services every trip message handler needs, injected as one.
 *
 * Without it each handler re-declared the same eight parameters only to hand them to
 * {@see AbstractTripMessageHandler}, and a ninth meant editing every constructor and every
 * test that builds one.
 */
final readonly class TripHandlerContext
{
    public function __construct(
        public ComputationTrackerInterface $computationTracker,
        public TripUpdatePublisherInterface $publisher,
        public TripGenerationTrackerInterface $generationTracker,
        public LoggerInterface $logger,
        public TripRequestRepositoryInterface $tripRequestRepository,
        public TripStageStoreInterface $stageStore,
        public MessageBusInterface $messageBus,
        public AlertRenderer $alertRenderer,
    ) {
    }
}
