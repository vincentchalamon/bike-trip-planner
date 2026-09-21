<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Concurrency\IfMatch;
use App\Concurrency\TripVersionEtag;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationDependencyResolver;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\Service\TripAnalysisDispatcher;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<TripRequest, Trip>
 */
final readonly class TripUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private ComputationDependencyResolver $dependencyResolver,
        private IdempotencyCheckerInterface $idempotencyChecker,
        private TripGenerationTrackerInterface $generationTracker,
        private Security $security,
        private TripLocker $tripLocker,
        private TripAnalysisDispatcher $analysisDispatcher,
    ) {
    }

    /**
     * @param TripRequest        $data
     * @param Patch              $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $id = $uriVariables['id'] ?? '';

        // Synchronous validations
        if (null !== $data->endDate && null !== $data->startDate && $data->endDate <= $data->startDate) {
            throw new UnprocessableEntityHttpException('End date must be after start date.');
        }

        // Retrieve existing request to check the persisted startDate (before applying the PATCH body)
        $existingRequest = $this->tripStateManager->getRequest($id);
        \assert($existingRequest instanceof TripRequest);
        $this->tripLocker->assertNotLocked($existingRequest);

        // Refresh locale on each PATCH: the account preference may have changed since
        // the trip was created.
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $this->tripStateManager->storeLocale($id, $user->getLocale());

        // Provider (TripRequestProvider) already threw 404 if the trip doesn't exist;
        // the processor only runs when $data is a valid, non-null TripRequest.
        // Reuse the request already fetched above for the lock check.
        $oldRequest = $existingRequest;

        // Everything that compares the old settings with the new must happen BEFORE the
        // write, for two independent reasons.
        //
        // Doctrine hands out the managed entity, and storeRequest() copies the incoming
        // fields onto that very instance — so $oldRequest is not "old" once the write has
        // run, and resolving afterwards compares the new values with themselves. The
        // functional suite never showed it: it is aliased to the Redis implementation, which
        // deserialises a fresh copy per read.
        //
        // And the precondition has to guard the write rather than follow it. Checked after,
        // a stale If-Match would persist the settings and only then answer 412 — a refusal
        // the caller is entitled to read as "nothing happened".
        $hasChanged = $this->idempotencyChecker->hasChanged($id, $data);
        $computationsToTrigger = $hasChanged ? $this->dependencyResolver->resolve($oldRequest, $data) : [];

        $generation = null;
        if ([] !== $computationsToTrigger) {
            // Criteria changed: bump generation so in-flight messages become stale. The
            // client's If-Match rides along and is compared under the write lock — a
            // settings edit that triggers a regeneration replaces every stage, so applying
            // it to a trip that moved on since is exactly what the precondition forbids.
            $generation = $this->generationTracker->increment($id, IfMatch::expectedVersion($context));
            TripVersionEtag::stamp($context, $generation);
        }

        // Always persist — non-computation fields (e.g. title) may have changed
        $this->tripStateManager->storeRequest($id, $data);

        // Check idempotency for computation-triggering fields only
        if (!$hasChanged) {
            $statuses = $this->computationTracker->getStatuses($id) ?? [];

            return new Trip(
                id: $id,
                computationStatus: $statuses,
                isLocked: $this->tripLocker->isLocked($existingRequest),
            );
        }

        $this->idempotencyChecker->saveHash($id, $data);

        if (null !== $generation) {
            foreach ($computationsToTrigger as $computation) {
                $this->computationTracker->resetComputation($id, $computation);
                $this->dispatchComputation($id, $computation, $generation);
            }
        }

        $statuses = $this->computationTracker->getStatuses($id) ?? [];

        return new Trip(
            id: $id,
            computationStatus: $statuses,
            isLocked: $this->tripLocker->isLocked($data),
        );
    }

    private function dispatchComputation(string $tripId, ComputationName $computation, int $generation): void
    {
        // Only the two root computations are built here. Every enrichment goes through the
        // dispatcher that already owns that mapping, so a PATCH and a structural edit cannot
        // disagree about which message a computation means (ADR-070).
        if (ComputationName::ROUTE === $computation) {
            $this->messageBus->dispatch(new FetchAndParseRoute($tripId, $generation));

            return;
        }

        if (ComputationName::STAGES === $computation) {
            $this->messageBus->dispatch(new GenerateStages($tripId, $generation));

            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        \assert($request instanceof TripRequest);

        $this->analysisDispatcher->dispatchOne($tripId, $request, $computation, $generation);
    }
}
