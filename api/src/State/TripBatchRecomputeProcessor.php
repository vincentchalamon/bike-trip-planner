<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Concurrency\IfMatch;
use App\Concurrency\TripVersionEtag;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\ApiResource\TripBatchRecomputeRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\ComputationDependencyResolver;
use App\Service\TripAnalysisDispatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

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
        #[Autowire(service: 'limiter.trip_recompute')]
        private RateLimiterFactory $recomputeLimiter,
    ) {
    }

    /**
     * @param TripBatchRecomputeRequest $data
     * @param Post                      $operation
     * @param array{id?: string}        $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $tripId = $uriVariables['id'] ?? '';

        if ('' === $tripId) {
            throw new NotFoundHttpException('Trip not found.');
        }

        // Cap per trip: recompute re-dispatches the full enrichment pipeline onto
        // the shared workers, so it must not be scriptable faster than they drain (SEC-010).
        if (!$this->recomputeLimiter->create($tripId)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
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

        // Increment generation to invalidate in-flight workers. The queue being replayed
        // was built against a version of the trip; comparing it under the write lock stops
        // a batch computed on a stale view from landing on stages that have since moved.
        $generation = $this->generationTracker->increment($tripId, IfMatch::expectedVersion($context));
        TripVersionEtag::stamp($context, $generation);

        // If the initial analysis has not fully settled yet, a minimal,
        // dependency-resolved recompute only re-dispatches a subset while the
        // generation bump discards every in-flight computation it does NOT cover.
        // Those stay "pending" forever: the enrichment gate never settles (no
        // terminal trip_ready/trip_complete, so the frontend loader spins) and
        // their results are lost — recette #649, adjusting the rider profile
        // mid-analysis. Re-run the full enrichment pipeline for the new
        // generation instead so nothing is stranded and the gate can settle.
        $progress = $this->computationTracker->getProgress($tripId);
        if ($progress['total'] > 0 && $progress['settled'] < $progress['total']) {
            $this->analysisDispatcher->dispatch($tripId, $request, $generation);

            return new Trip(id: $tripId);
        }

        $stageIds = array_map(static fn (Stage $stage): string => $stage->id, $stages);
        $hasDates = $request->startDate instanceof \DateTimeImmutable;

        $messages = $this->dependencyResolver->resolve(
            $tripId,
            $data->modifications,
            $stageIds,
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
