<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AccommodationScanRequest;
use App\ApiResource\Trip;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\OsmOverpassQueryBuilder;
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
    ) {
    }

    /**
     * @param AccommodationScanRequest  $data
     * @param Post                      $operation
     * @param array{tripId?: string}    $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $tripId = $uriVariables['tripId'] ?? '';

        if (null === $this->tripStateManager->getRequest($tripId)) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $tripId));
        }

        $radiusKm = max(1, min($data->radiusKm, OsmOverpassQueryBuilder::MAX_ACCOMMODATION_RADIUS_METERS / 1000));
        $radiusMeters = $radiusKm * 1000;

        $this->computationTracker->resetComputation($tripId, ComputationName::ACCOMMODATIONS);
        $this->messageBus->dispatch(new ScanAccommodations($tripId, $radiusMeters));

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        return new Trip(
            id: $tripId,
            computationStatus: $statuses,
        );
    }
}
