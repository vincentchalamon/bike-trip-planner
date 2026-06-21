<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\Stage;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Enum\TripStatus;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\RouteParser\GpxRouteParserInterface;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Encapsulates GPX upload business logic: trip initialization, route storage,
 * synchronous structural computation (pacing), computation tracking, Mercure
 * publishing, and the asynchronous enrichment fan-out.
 *
 * Extracted from GpxUploadController to satisfy SRP and reduce coupling.
 *
 * ADR-043: the pacing is pure local CPU, so it runs synchronously here — the HTTP
 * response already carries the computed stages and the persisted `ready` status.
 * Only the network/LLM enrichments stay asynchronous (dispatched at the end).
 */
final readonly class GpxUploadService
{
    public function __construct(
        private GpxRouteParserInterface $gpxParser,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private TripUpdatePublisherInterface $publisher,
        private StructuralComputationService $structuralComputation,
        private TripAnalysisDispatcher $analysisDispatcher,
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
     * Creates a trip from parsed GPX data, computes its stages synchronously, then
     * dispatches the asynchronous enrichments.
     *
     * @param list<Coordinate> $points
     *
     * @return array{tripId: string, computationStatus: array<string, string>, totalDistance: float, totalElevation: int, totalElevationLoss: int, status: string, stages: list<array<string, mixed>>}
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

        // ADR-043: pacing is pure local CPU — compute the stages synchronously so the
        // structural trip is already available in the HTTP response.
        $request = $this->tripStateManager->getRequest($tripId) ?? $tripRequest;
        $stages = $this->structuralComputation->generateStages($tripId, $request);
        $status = $this->storeStructuralStages($tripId, $stages);

        // Hand off the network/LLM enrichments to the workers (unchanged async fan-out).
        $this->analysisDispatcher->dispatch($tripId, $request);

        return [
            'tripId' => $tripId,
            'computationStatus' => $this->buildComputationStatus($computations),
            'totalDistance' => $totalDistance,
            'totalElevation' => $totalElevation,
            'totalElevationLoss' => $totalElevationLoss,
            'status' => $status->value,
            'stages' => $this->structuralComputation->serializeStagesForEvent($stages),
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

    /**
     * Persists the synchronously computed stages, marks the STAGES computation done,
     * publishes the `stages_computed` event and the progress step, and posts the
     * structural `ready` status (ADR-043) once at least {@see TripStatus::MIN_STAGES}
     * stages exist.
     *
     * The terminal enrichment gate is intentionally NOT evaluated here — readiness is
     * structural and must not depend on the asynchronous enrichments settling.
     *
     * @param list<Stage> $stages
     */
    private function storeStructuralStages(string $tripId, array $stages): TripStatus
    {
        $this->computationTracker->markRunning($tripId, ComputationName::STAGES);

        if (\count($stages) < TripStatus::MIN_STAGES) {
            $this->publisher->publishValidationError($tripId, 'MIN_STAGES', 'A minimum of 2 stages is required.');
        }

        $this->tripStateManager->storeStages($tripId, $stages);
        $this->computationTracker->markDone($tripId, ComputationName::STAGES);

        $status = TripStatus::DRAFT;
        if (\count($stages) >= TripStatus::MIN_STAGES) {
            $status = TripStatus::READY;
            $this->tripStateManager->storeStatus($tripId, $status->value);
        }

        $this->publisher->publish(
            $tripId,
            MercureEventType::STAGES_COMPUTED,
            ['stages' => $this->structuralComputation->serializeStagesForEvent($stages)],
        );

        $progress = $this->computationTracker->getProgress($tripId);
        if (0 !== $progress['total']) {
            $this->publisher->publishComputationStepCompleted(
                $tripId,
                ComputationName::STAGES,
                $progress['completed'],
                $progress['total'],
                $progress['failed'],
            );
        }

        return $status;
    }

    /**
     * @param list<ComputationName> $computations
     *
     * @return array<string, string>
     */
    private function buildComputationStatus(array $computations): array
    {
        $structural = ComputationName::structuralPipeline();
        $result = [];
        foreach ($computations as $computation) {
            $result[$computation->value] = \in_array($computation, $structural, true) ? 'done' : 'pending';
        }

        return $result;
    }
}
