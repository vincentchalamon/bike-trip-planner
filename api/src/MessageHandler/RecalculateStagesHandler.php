<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Analyzer\AnalyzerRegistryInterface;
use App\ComputationTracker\ComputationTrackerInterface;
use App\GpxWriter\GpxWriterInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RecalculateStagesHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private GpxWriterInterface $gpxWriter,
        private AnalyzerRegistryInterface $analyzerRegistry,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(RecalculateStages $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $affectedIndices = $message->affectedIndices;
        // If empty, recalculate all stages (e.g. after a move)
        if ([] === $affectedIndices) {
            $affectedIndices = array_keys($stages);
        }

        foreach ($affectedIndices as $index) {
            if (!isset($stages[$index])) {
                continue;
            }

            $stage = $stages[$index];

            // Regenerate GPX
            $stage->gpxContent = $this->gpxWriter->generate(
                $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                \sprintf('Étape %d', $stage->dayNumber),
            );
        }

        // Run continuity analysis if requested
        if ($message->checkContinuity) {
            $stageCount = \count($stages);
            for ($i = 0; $i < $stageCount; ++$i) {
                $context = ['nextStage' => $stages[$i + 1] ?? null, 'tripDays' => $stageCount];
                $alerts = $this->analyzerRegistry->analyze($stages[$i], $context);
                // Clear old alerts and re-add
                $stages[$i]->alerts = [];
                foreach ($alerts as $alert) {
                    $stages[$i]->addAlert($alert);
                }
            }
        }

        $this->tripStateManager->storeStages($tripId, $stages);

        // Dispatch POI/Accommodation scans for affected stages
        if ([] !== $affectedIndices) {
            $this->messageBus->dispatch(new ScanPois($tripId));
            $this->messageBus->dispatch(new ScanAccommodations($tripId));
        }
    }
}
