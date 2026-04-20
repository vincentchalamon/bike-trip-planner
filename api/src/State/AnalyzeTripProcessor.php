<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripAnalysisDispatcher;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Triggers the full enrichment pipeline for a trip whose stages have been pre-computed.
 *
 * Decouples preview (stage generation) from analysis (enrichment): the preview step stops
 * after stages are generated; the user explicitly requests analysis via this endpoint.
 *
 * @implements ProcessorInterface<TripRequest, Trip>
 */
final readonly class AnalyzeTripProcessor implements ProcessorInterface
{
    /**
     * Computations considered part of the enrichment pipeline (i.e. triggered by this endpoint).
     *
     * ROUTE and STAGES belong to the preview phase and are therefore excluded from both
     * the "already running" check and the reset/re-dispatch cycle.
     *
     * @var list<ComputationName>
     */
    private const array ANALYSIS_COMPUTATIONS = [
        ComputationName::OSM_SCAN,
        ComputationName::POIS,
        ComputationName::ACCOMMODATIONS,
        ComputationName::TERRAIN,
        ComputationName::WEATHER,
        ComputationName::CALENDAR,
        ComputationName::WIND,
        ComputationName::BIKE_SHOPS,
        ComputationName::WATER_POINTS,
        ComputationName::CULTURAL_POIS,
        ComputationName::RAILWAY_STATIONS,
        ComputationName::HEALTH_SERVICES,
        ComputationName::BORDER_CROSSING,
        ComputationName::EVENTS,
    ];

    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private TripAnalysisDispatcher $analysisDispatcher,
    ) {
    }

    /**
     * @param TripRequest        $data         The TripRequest resolved by {@see TripRequestProvider}
     * @param Post               $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        \assert($data instanceof TripRequest);
        $tripId = $uriVariables['id'] ?? '';

        if ('' === $tripId) {
            throw new NotFoundHttpException('Trip not found.');
        }

        // 422: the trip must have pre-computed stages before analysis can be requested.
        $stages = $this->tripStateManager->getStages($tripId);
        if (null === $stages || [] === $stages) {
            throw new UnprocessableEntityHttpException('Trip has no stages to analyze.');
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        // 409: an analysis is already in flight; the client should wait for it to complete.
        if ($this->isAnalysisRunning($statuses)) {
            throw new ConflictHttpException('An analysis is already in progress for this trip.');
        }

        // Re-arm every enrichment computation so the tracker reflects the new pipeline
        // without discarding the preview-phase statuses (ROUTE, STAGES).
        foreach (self::ANALYSIS_COMPUTATIONS as $computation) {
            $this->computationTracker->resetComputation($tripId, $computation);
        }

        $generation = $this->generationTracker->current($tripId);

        $this->analysisDispatcher->dispatch($tripId, $data, $generation);

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        return new Trip(
            id: $tripId,
            computationStatus: $statuses,
        );
    }

    /**
     * @param array<string, string> $statuses
     */
    private function isAnalysisRunning(array $statuses): bool
    {
        return array_any(self::ANALYSIS_COMPUTATIONS, fn ($computation): bool => ($statuses[$computation->value] ?? null) === 'running');
    }
}
