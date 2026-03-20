<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\Message\ScanAllOsmData;
use App\Repository\TripRequestRepositoryInterface;
use App\RouteFetcher\RouteFetcherRegistryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FetchAndParseRouteHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private RouteFetcherRegistryInterface $routeFetcherRegistry,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(FetchAndParseRoute $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripStateManager->getRequest($tripId);

        if (!$request instanceof TripRequest || null === $request->sourceUrl) {
            return;
        }

        $sourceUrl = $request->sourceUrl;

        $this->executeWithTracking($tripId, ComputationName::ROUTE, function () use ($tripId, $sourceUrl, $generation): void {
            $fetcher = $this->routeFetcherRegistry->get($sourceUrl);
            $result = $fetcher->fetch($sourceUrl);

            if ([] === $result->tracks) {
                $this->publisher->publishValidationError($tripId, 'EMPTY_ROUTE', 'Empty route.');

                return;
            }

            $allPoints = array_merge(...$result->tracks);

            if ([] === $allPoints) {
                $this->publisher->publishValidationError($tripId, 'EMPTY_ROUTE', 'Empty route.');

                return;
            }

            $this->tripStateManager->storeRawPoints($tripId, array_map(
                static fn ($c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $allPoints,
            ));

            $this->tripStateManager->storeSourceType($tripId, $result->sourceType->value);
            $this->tripStateManager->storeTitle($tripId, $result->title);

            // Store decimated points (full route for pacing) for single-track sources
            $decimated = $this->routeSimplifier->simplify($allPoints);
            $this->tripStateManager->storeDecimatedPoints($tripId, array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $decimated,
            ));

            $totalDistance = $this->distanceCalculator->calculateTotalDistance($allPoints);
            $totalElevation = $this->elevationCalculator->calculateTotalAscent($allPoints);
            $totalElevationLoss = $this->elevationCalculator->calculateTotalDescent($allPoints);

            $this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
                'totalDistance' => round($totalDistance, 1),
                'totalElevation' => (int) $totalElevation,
                'totalElevationLoss' => (int) $totalElevationLoss,
                'sourceType' => $result->sourceType->value,
                'title' => $result->title,
            ]);

            // Store raw tracks for collection source type (multiple tracks = 1 stage per track)
            if (\count($result->tracks) > 1) {
                $tracksData = [];
                foreach ($result->tracks as $track) {
                    $tracksData[] = array_map(
                        static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                        $track,
                    );
                }

                $this->tripStateManager->storeTracksData($tripId, $tracksData);
            }

            $this->messageBus->dispatch(new GenerateStages($tripId, $generation));
            $this->messageBus->dispatch(new ScanAllOsmData($tripId, $generation));
        }, $generation);
    }
}
