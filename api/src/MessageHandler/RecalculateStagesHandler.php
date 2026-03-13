<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
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

        $this->tripStateManager->storeStages($tripId, $stages);

        // Publish updated stages so the frontend refreshes immediately
        $this->publisher->publish($tripId, MercureEventType::STAGES_COMPUTED, [
            'stages' => array_map(
                static fn (Stage $s): array => [
                    'dayNumber' => $s->dayNumber,
                    'distance' => round($s->distance, 1),
                    'elevation' => (int) $s->elevation,
                    'elevationLoss' => (int) $s->elevationLoss,
                    'startPoint' => [
                        'lat' => $s->startPoint->lat,
                        'lon' => $s->startPoint->lon,
                        'ele' => $s->startPoint->ele,
                    ],
                    'endPoint' => [
                        'lat' => $s->endPoint->lat,
                        'lon' => $s->endPoint->lon,
                        'ele' => $s->endPoint->ele,
                    ],
                    'label' => $s->label,
                ],
                $stages,
            ),
            'affectedIndices' => array_values($affectedIndices),
        ]);

        // Dispatch POI/Accommodation/BikeShop scans for affected stages
        if ([] !== $affectedIndices) {
            $this->messageBus->dispatch(new ScanPois($tripId));
            if (!$message->skipAccommodationScan) {
                $request = $this->tripStateManager->getRequest($tripId);
                $this->messageBus->dispatch(new ScanAccommodations($tripId, enabledAccommodationTypes: $request?->enabledAccommodationTypes ?? ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'motel', 'hotel']));
            }

            $this->messageBus->dispatch(new CheckBikeShops($tripId));
            $this->messageBus->dispatch(new AnalyzeTerrain($tripId));
        }
    }
}
