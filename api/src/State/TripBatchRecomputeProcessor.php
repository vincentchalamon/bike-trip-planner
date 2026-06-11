<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripBatchRecomputeRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\ComputationDependencyResolver;
use App\Service\TripAnalysisDispatcher;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Processes the batch recompute endpoint: applies N pending modifications in a
 * single request, dispatching only the minimal set of handlers needed.
 *
 * When the initial analysis is still in flight, falls back to the full enrichment
 * pipeline (via {@see TripAnalysisDispatcher}) instead of the minimal resolver to
 * prevent computations from being stranded by the generation bump — see #649.
 *
 * This avoids N sequential recomputations when the user accumulates several
 * modifications before confirming them. The dependency resolution is delegated
 * to {@see ComputationDependencyResolver}.
 *
 * @implements ProcessorInterface<TripBatchRecomputeRequest, Trip>
 */
final readonly class TripBatchRecomputeProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private TripGenerationTrackerInterface $generationTracker,
        private ComputationDependencyResolver $dependencyResolver,
        private MessageBusInterface $messageBus,
        private ComputationTrackerInterface $computationTracker,
        private TripAnalysisDispatcher $analysisDispatcher,
    ) {
    }

    /**
     * @param TripBatchRecomputeRequest $data
     * @param Post                      $operation
     * @param array{id?: string}        $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        \assert($data instanceof TripBatchRecomputeRequest);
        $tripId = $uriVariables['id'] ?? '';

        if ('' === $tripId) {
            throw new NotFoundHttpException('Trip not found.');
        }

        $stages = $this->tripStateManager->getStages($tripId);
        if (null === $stages) {
            throw new NotFoundHttpException('Trip not found.');
        }

        if ([] === $stages) {
            throw new UnprocessableEntityHttpException('Trip has no stages to recompute.');
        }

        $request = $this->tripStateManager->getRequest($tripId);
        if (!$request instanceof TripRequest) {
            throw new NotFoundHttpException('Trip not found.');
        }

        // Increment generation to invalidate in-flight workers
        $generation = $this->generationTracker->increment($tripId);

        // If the initial analysis has not fully settled yet, a minimal,
        // dependency-resolved recompute only re-dispatches a subset while the
        // generation bump discards every in-flight computation it does NOT cover.
        // Those stay "pending" forever: the enrichment gate never settles (no
        // terminal trip_ready/trip_complete, so the frontend loader spins) and
        // their results are lost — recette #649, adjusting the rider profile
        // mid-analysis. Re-run the full enrichment pipeline for the new
        // generation instead so nothing is stranded and the gate can settle.
        $progress = $this->computationTracker->getProgress($tripId);
        if ($progress['total'] > 0 && $progress['completed'] + $progress['failed'] < $progress['total']) {
            $this->analysisDispatcher->dispatch($tripId, $request, $generation);

            return new Trip(id: $tripId);
        }

        $allStageIndices = array_keys($stages);
        $hasDates = $request->startDate instanceof \DateTimeImmutable;

        $messages = $this->dependencyResolver->resolve(
            $tripId,
            $data->modifications,
            $allStageIndices,
            $hasDates,
            $request->enabledAccommodationTypes,
            $generation,
        );

        foreach ($messages as $message) {
            $this->messageBus->dispatch($message);
        }

        return new Trip(id: $tripId);
    }
}
