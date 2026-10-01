<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\Mercure\MercureEventType;
use App\Message\RecalculateRouteSegment;
use App\Routing\RoutingProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecalculateRouteSegmentHandler extends AbstractTripMessageHandler
{
    public function __construct(
        TripHandlerContext $context,
        private RoutingProviderInterface $routingProvider,
    ) {
        parent::__construct($context);
    }

    public function __invoke(RecalculateRouteSegment $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        // Resolve by identity: an edit since this message was sent may have moved the
        // stage, and a stale position would rewrite another stage's geometry.
        $stage = array_find($stages, fn ($candidate): bool => $candidate->id === $message->stageId);

        if (null === $stage) {
            return;
        }

        $waypoint = new Coordinate($message->waypointLat, $message->waypointLon);

        $this->executeWithTracking($message, function () use ($tripId, $message, $stage, $waypoint): void {
            $result = $this->routingProvider->calculateRoute($stage->startPoint, $stage->endPoint, [$waypoint]);

            $this->publisher->publish($tripId, MercureEventType::ROUTE_SEGMENT_RECALCULATED, [
                'stageId' => $message->stageId,
                'reason' => $message->reason,
                'distance' => $result->distance,
                'elevationGain' => $result->elevationGain,
                'duration' => $result->duration,
                'coordinates' => array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                    $result->coordinates,
                ),
            ]);
        });
    }
}
