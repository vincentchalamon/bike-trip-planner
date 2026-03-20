<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\RecalculateRouteSegment;
use App\Repository\TripRequestRepositoryInterface;
use App\Routing\RoutingProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecalculateRouteSegmentHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private RoutingProviderInterface $routingProvider,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(RecalculateRouteSegment $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        if (!isset($stages[$message->stageIndex])) {
            return;
        }

        $stage = $stages[$message->stageIndex];
        $waypoint = new Coordinate($message->waypointLat, $message->waypointLon);

        $this->executeWithTracking($tripId, ComputationName::ROUTE_SEGMENT, function () use ($tripId, $message, $stage, $waypoint): void {
            $result = $this->routingProvider->calculateRoute($stage->startPoint, $stage->endPoint, [$waypoint]);

            $this->publisher->publish($tripId, MercureEventType::ROUTE_SEGMENT_RECALCULATED, [
                'stageIndex' => $message->stageIndex,
                'reason' => $message->reason,
                'distance' => $result->distance,
                'elevationGain' => $result->elevationGain,
                'duration' => $result->duration,
                'coordinates' => array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                    $result->coordinates,
                ),
            ]);
        }, $generation);
    }
}
