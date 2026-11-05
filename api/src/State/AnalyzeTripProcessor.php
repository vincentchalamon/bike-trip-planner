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
use App\Enum\ComputationTrigger;
use App\Entity\User;
use App\RateLimiter\RetryAfter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use App\Repository\TripStageStoreInterface;
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
        private TripStageStoreInterface $stageStore,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private TripAnalysisDispatcher $analysisDispatcher,
        private TripLocker $tripLocker,
        private Security $security,
        #[Target('trip_analyze')]
        private RateLimiterFactoryInterface $tripAnalyzeLimiter,
        private ClockInterface $clock,
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
        $stages = $this->stageStore->getStages($tripId);
        if (null === $stages || [] === $stages) {
            throw new UnprocessableEntityHttpException('Trip has no stages to analyze.');
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];

        // 409: an analysis is already in flight; the client should wait for it to complete.
        if ($this->isAnalysisInFlight($statuses, $data)) {
            throw new ConflictHttpException('An analysis is already in progress for this trip.');
        }

        // Each call queues the whole enrichment pipeline onto the shared workers; cap per user
        // so it cannot be scripted faster than they drain, across all of the user's trips.
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $limit = $this->tripAnalyzeLimiter->create($user->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(RetryAfter::seconds($limit, $this->clock));
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
     * A queued computation is as much in flight as a running one: it sits `pending` until a
     * worker picks it up, so testing `running` alone let a second call queue the pipeline
     * again behind the first. Only the computations this endpoint actually dispatches count
     * when pending: the ones withheld for want of a start date, and the ones cascaded from
     * another, stay `pending` indefinitely and would lock the endpoint for good.
     *
     * @param array<string, string> $statuses
     */
    private function isAnalysisInFlight(array $statuses, TripRequest $request): bool
    {
        $dispatched = array_filter(
            ComputationName::dependingOn(ComputationTrigger::GEOMETRY, ComputationTrigger::DATES),
            static fn (ComputationName $c): bool => $request->startDate instanceof \DateTimeImmutable || !$c->requiresStartDate(),
        );

        return array_any(ComputationName::analysisPipeline(), static function (ComputationName $computation) use ($statuses, $dispatched): bool {
            $status = $statuses[$computation->value] ?? null;

            return ComputationStatus::RUNNING->value === $status
                || (ComputationStatus::PENDING->value === $status && \in_array($computation, $dispatched, true));
        });
    }
}
