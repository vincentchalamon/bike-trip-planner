<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateStages;
use App\Message\ScanAllOsmData;
use App\Repository\TripRequestRepositoryInterface;
use App\RouteParser\GpxRouteParserInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Encapsulates GPX upload business logic: trip initialization, route storage,
 * computation tracking, Mercure publishing, and downstream message dispatching.
 *
 * Extracted from GpxUploadController to satisfy SRP and reduce coupling.
 */
final readonly class GpxUploadService
{
    public function __construct(
        private GpxRouteParserInterface $gpxParser,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private MessageBusInterface $messageBus,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private TripUpdatePublisherInterface $publisher,
    ) {
    }

    /**
     * Parses GPX content and returns track points.
     *
     * @return list<Coordinate>
     *
     * @throws \RuntimeException When GPX content is invalid
     */
    public function parseGpx(string $content): array
    {
        return $this->gpxParser->parse($content);
    }

    /**
     * Extracts the title from GPX content.
     */
    public function extractTitle(string $content): ?string
    {
        return $this->gpxParser->extractTitle($content);
    }

    /**
     * Creates a trip from parsed GPX data and dispatches downstream computations.
     *
     * @param list<Coordinate> $points
     *
     * @return array{tripId: string, computationStatus: array<string, string>, totalDistance: float, totalElevation: int, totalElevationLoss: int}
     */
    public function createTrip(
        array $points,
        ?string $title,
        TripRequest $tripRequest,
        string $locale,
    ): array {
        $tripId = Uuid::v7()->toRfc4122();

        $this->tripStateManager->initializeTrip($tripId, $tripRequest);
        $this->tripStateManager->storeLocale($tripId, $locale);

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        $this->storeRouteData($tripId, $points, $title);

        $totalDistance = round($this->distanceCalculator->calculateTotalDistance($points), 1);
        $totalElevation = (int) $this->elevationCalculator->calculateTotalAscent($points);
        $totalElevationLoss = (int) $this->elevationCalculator->calculateTotalDescent($points);

        $this->publishRouteEvent($tripId, $totalDistance, $totalElevation, $totalElevationLoss, $title);
        $this->dispatchDownstreamMessages($tripId);

        return [
            'tripId' => $tripId,
            'computationStatus' => $this->buildComputationStatus($computations),
            'totalDistance' => $totalDistance,
            'totalElevation' => $totalElevation,
            'totalElevationLoss' => $totalElevationLoss,
        ];
    }

    /**
     * @param list<Coordinate> $points
     */
    private function storeRouteData(string $tripId, array $points, ?string $title): void
    {
        $this->tripStateManager->storeRawPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $points,
        ));

        $this->tripStateManager->storeSourceType($tripId, SourceType::GPX_UPLOAD->value);
        $this->tripStateManager->storeTitle($tripId, $title);

        $decimated = $this->routeSimplifier->simplify($points);
        $this->tripStateManager->storeDecimatedPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $decimated,
        ));
    }

    private function publishRouteEvent(string $tripId, float $totalDistance, int $totalElevation, int $totalElevationLoss, ?string $title): void
    {
        $this->computationTracker->markRunning($tripId, ComputationName::ROUTE);
        $this->computationTracker->markDone($tripId, ComputationName::ROUTE);

        $this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
            'totalDistance' => $totalDistance,
            'totalElevation' => $totalElevation,
            'totalElevationLoss' => $totalElevationLoss,
            'sourceType' => SourceType::GPX_UPLOAD->value,
            'title' => $title,
        ]);
    }

    private function dispatchDownstreamMessages(string $tripId): void
    {
        $this->messageBus->dispatch(new GenerateStages($tripId));
        $this->messageBus->dispatch(new ScanAllOsmData($tripId));
    }

    /**
     * @param list<ComputationName> $computations
     *
     * @return array<string, string>
     */
    private function buildComputationStatus(array $computations): array
    {
        $status = [];
        foreach ($computations as $computation) {
            $status[$computation->value] = ComputationName::ROUTE === $computation ? 'done' : 'pending';
        }

        return $status;
    }
}
