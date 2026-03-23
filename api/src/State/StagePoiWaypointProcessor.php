<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StagePoiWaypointRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\RecalculateRouteSegment;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

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
        private ObjectMapperInterface $objectMapper,
        private TripGenerationTrackerInterface $generationTracker,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param StagePoiWaypointRequest                    $data
     * @param Post                                       $operation
     * @param array{tripId?: string, index?: int|string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $rawIndex = $uriVariables['index'] ?? null;

        if (!\is_numeric($rawIndex)) {
            throw new BadRequestHttpException('Stage index must be a valid integer.');
        }

        $index = (int) $rawIndex;

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        \assert($tripRequest instanceof \App\ApiResource\TripRequest);
        $this->tripLocker->assertNotLocked($tripRequest);

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        $stage = $stages[$index];

        $waypointLat = $data->waypointLat ?? throw new BadRequestHttpException('waypointLat is required.');
        $waypointLon = $data->waypointLon ?? throw new BadRequestHttpException('waypointLon is required.');

        $generation = $this->generationTracker->current($tripId);

        $this->messageBus->dispatch(new RecalculateRouteSegment(
            tripId: $tripId,
            stageIndex: $index,
            waypointLat: $waypointLat,
            waypointLon: $waypointLon,
            reason: 'poi_detour',
            generation: $generation,
        ));

        return $this->objectMapper->map($stage, StageResponse::class);
    }
}
