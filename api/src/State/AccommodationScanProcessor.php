<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AccommodationScanRequest;
use App\ApiResource\Trip;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<AccommodationScanRequest, Trip>
 */
final readonly class AccommodationScanProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
    ) {
    }

    /**
     * @param AccommodationScanRequest $data
     * @param Post                     $operation
     * @param array{tripId?: string}   $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $tripId = $uriVariables['tripId'] ?? '';

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if (!$tripRequest instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $tripId));
        }

        $radiusMeters = $data->radiusKm * 1000;

        $enabledAccommodationTypes = $tripRequest->enabledAccommodationTypes;

        $generation = $this->generationTracker->current($tripId);

        $this->computationTracker->resetComputation($tripId, ComputationName::ACCOMMODATIONS);
        $this->messageBus->dispatch(new ScanAccommodations($tripId, $radiusMeters, $data->stageIndex, $enabledAccommodationTypes, isExpandScan: true, generation: $generation));

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        return new Trip(
            id: $tripId,
            computationStatus: $statuses,
        );
    }
}
