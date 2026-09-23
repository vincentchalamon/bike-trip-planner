<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StagePoiWaypointRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mapper\StageResponseMapper;
use App\Message\RecalculateRouteSegment;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles adding a cultural POI as a waypoint to a stage route.
 *
 * Dispatches RecalculateRouteSegment so Valhalla recomputes the stage
 * geometry via the POI coordinates (ADR-017). The result is pushed to
 * the frontend via Mercure SSE (route_segment_recalculated).
 *
 * @implements ProcessorInterface<StagePoiWaypointRequest, StageResponse>
 */
final readonly class StagePoiWaypointProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private StageResponseMapper $stageResponseMapper,
        private TripGenerationTrackerInterface $generationTracker,
        private StageLocator $stageLocator,
    ) {
    }

    /**
     * @param StagePoiWaypointRequest                  $data
     * @param Post                                     $operation
     * @param array{tripId?: string, stageId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $stageId = $uriVariables['stageId'] ?? '';

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        \assert($tripRequest instanceof TripRequest);

        $stages = $this->tripStateManager->getStages($tripId) ?? [];
        $index = $this->stageLocator->indexOf($stages, $stageId);
        $stage = $stages[$index];

        $waypointLat = $data->waypointLat ?? throw new BadRequestHttpException('waypointLat is required.');
        $waypointLon = $data->waypointLon ?? throw new BadRequestHttpException('waypointLon is required.');

        $generation = $this->generationTracker->current($tripId);

        $this->messageBus->dispatch(new RecalculateRouteSegment(
            tripId: $tripId,
            stageId: $stage->id,
            waypointLat: $waypointLat,
            waypointLon: $waypointLon,
            reason: 'poi_detour',
            generation: $generation,
        ));

        return $this->stageResponseMapper->map($stage);
    }
}
