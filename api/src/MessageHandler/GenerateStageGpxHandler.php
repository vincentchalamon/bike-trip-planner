<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\GpxWriter\GpxWriterInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Message\GenerateStageGpx;
use App\Message\ScanAccommodations;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class GenerateStageGpxHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private GpxWriterInterface $gpxWriter,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(GenerateStageGpx $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::STAGE_GPX, function () use ($tripId, $stages): void {
            foreach ($stages as $i => $stage) {
                $gpxContent = $this->gpxWriter->generate(
                    $stage->geometry,
                    \sprintf('Étape %d', $stage->dayNumber),
                );

                $stage->gpxContent = $gpxContent;

                $this->publisher->publish($tripId, MercureEventType::STAGE_GPX_READY, [
                    'stageIndex' => $i,
                    'gpxContent' => $gpxContent,
                ]);
            }

            $this->tripStateManager->storeStages($tripId, $stages);

            $this->messageBus->dispatch(new ScanPois($tripId));
            $this->messageBus->dispatch(new ScanAccommodations($tripId));
            $this->messageBus->dispatch(new AnalyzeTerrain($tripId));
        });
    }
}
