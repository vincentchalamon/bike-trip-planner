<?php

declare(strict_types=1);

namespace App\State;

use App\Enum\ComputationStatus;
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
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private TripAnalysisDispatcher $analysisDispatcher,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param TripRequest        $data         The TripRequest resolved by {@see TripRequestProvider}
     * @param Post               $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
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
        foreach (ComputationName::analysisPipeline() as $computation) {
            $this->computationTracker->resetComputation($tripId, $computation);
        }

        $generation = $this->generationTracker->current($tripId);

        $this->analysisDispatcher->dispatch($tripId, $data, $generation);

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        return new Trip(
            id: $tripId,
            computationStatus: $statuses,
            isLocked: $this->tripLocker->isLocked($data),
        );
    }

    /**
     * @param array<string, string> $statuses
     */
    private function isAnalysisRunning(array $statuses): bool
    {
        return array_any(ComputationName::analysisPipeline(), fn (ComputationName $computation): bool => ($statuses[$computation->value] ?? null) === ComputationStatus::RUNNING->value);
    }
}
