<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StagePoiWaypointRequest;
use App\ApiResource\StageResponse;
use App\Message\RecalculateRouteSegment;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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
    ) {
    }

    /**
     * @param StagePoiWaypointRequest             $data
     * @param Post                                $operation
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        if (null === $data->waypointLat || null === $data->waypointLon) {
            throw new UnprocessableEntityHttpException('waypointLat and waypointLon are required.');
        }

        $stage = $stages[$index];

        $this->messageBus->dispatch(new RecalculateRouteSegment(
            tripId: $tripId,
            stageIndex: $index,
            waypointLat: $data->waypointLat,
            waypointLon: $data->waypointLon,
            reason: 'poi_detour',
        ));

        return $this->objectMapper->map($stage, StageResponse::class);
    }
}
