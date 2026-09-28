<?php

declare(strict_types=1);

namespace App\Service;

use App\State\TripLocker;
use App\Enum\ComputationStatus;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Enum\TripStatus;
use App\Mercure\ProgressPublisher;
use App\Entity\User;
use App\RouteParser\GpxRouteParserInterface;
use App\Repository\TripRequestRepositoryInterface;

/**
 * Creates a trip from an uploaded GPX file: the same bootstrap as a trip created from a URL
 * ({@see TripBootstrapper}), run to the end of its structural steps inside the request.
 *
 * ADR-043: the pacing is pure local CPU, so it runs synchronously here — the HTTP
 * response already carries the computed stages and the persisted `ready` status.
 * Only the network/LLM enrichments stay asynchronous (dispatched at the end).
 */
final readonly class GpxUploadService implements GpxUploadServiceInterface
{
    public function __construct(
        private GpxRouteParserInterface $gpxParser,
        private TripBootstrapper $bootstrapper,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private ProgressPublisher $progress,
        private TripLocker $tripLocker,
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
     * @return array{tripId: string, computationStatus: array<string, string>, totalDistance: float, totalElevation: int, totalElevationLoss: int, status: string, isLocked: bool, stages: list<array<string, mixed>>}
     */
    public function createTrip(
        array $points,
        ?string $title,
        TripRequest $tripRequest,
        string $locale,
        User $user,
    ): array {
        $tripId = $this->bootstrapper->create($tripRequest, $user, $locale);

        $this->computationTracker->markRunning($tripId, ComputationName::ROUTE);
        $totals = $this->bootstrapper->storeRoute($tripId, $points, SourceType::GPX_UPLOAD, $title);
        $this->computationTracker->markDone($tripId, ComputationName::ROUTE);

        // ADR-043: pacing is pure local CPU — compute the stages synchronously so the
        // structural trip is already available in the HTTP response. Unlike the worker, no
        // progress step for the route: the response already says it is done.
        $request = $this->tripStateManager->getRequest($tripId) ?? $tripRequest;
        $this->computationTracker->markRunning($tripId, ComputationName::STAGES);
        $stages = $this->bootstrapper->storeStages($tripId, $request);
        $this->computationTracker->markDone($tripId, ComputationName::STAGES);
        $this->progress->publish($tripId, ComputationName::STAGES);

        // Hand off the network/LLM enrichments to the workers (unchanged async fan-out).
        //
        // Stamped with the trip's current generation. Without it every message of a
        // GPX-imported trip carried `generation: null`, which the staleness guard reads as
        // "never stale" — so half the product's trips had no guard at all, and an edit made
        // during their analysis landed on top of workers still writing (ADR-073).
        $this->analysisDispatcher->dispatch($tripId, $request, $this->generationTracker->current($tripId));

        return [
            'tripId' => $tripId,
            'computationStatus' => $this->buildComputationStatus(ComputationName::pipeline()),
            ...$totals,
            'status' => (\count($stages) >= TripStatus::MIN_STAGES ? TripStatus::READY : TripStatus::DRAFT)->value,
            // The hand-built 202 body mirrors the Trip resource, so it carries what the
            // resource carries — this was the one field it omitted (ADR-074).
            'isLocked' => $this->tripLocker->isLocked($request),
            'stages' => $stages,
        ];
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
            $result[$computation->value] = \in_array($computation, $structural, true) ? ComputationStatus::DONE->value : ComputationStatus::PENDING->value;
        }

        return $result;
    }
}
