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
use App\ComputationTracker\ComputationSupersession;
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
        private TripGenerationTrackerInterface $generationTracker,
        private Security $security,
        private TripLocker $tripLocker,
        private TripAnalysisDispatcher $analysisDispatcher,
        private ComputationSupersession $supersession,
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

        // The settings as they were before this request: what the lock must judge, and the only
        // sound thing to compare $data against.
        //
        // Not a second getRequest(): under Doctrine that returns the managed entity, which is
        // the very object API Platform just deserialised the PATCH body into (ReadProvider
        // hands the operation provider's result to DeserializeProvider, which sets it as
        // OBJECT_TO_POPULATE). Resolving against it compared the new values with themselves
        // and answered "nothing changed" for every PATCH ever made. The functional suite
        // could not see it: the repository interface was aliased to the transient
        // implementation, which deserialises a fresh copy per read.
        //
        // ReadProvider already clones the resource before deserialisation and publishes it as
        // `previous_data` — a documented processor context key ({@see ProcessorInterface}).
        // A shallow clone is enough here: every compared field is a scalar, an array, or a
        // DateTimeImmutable that the property hook replaces rather than mutates.
        $oldRequest = $context['previous_data'] ?? null;
        \assert($oldRequest instanceof TripRequest);
        \assert($oldRequest !== $data, 'previous_data must not be the object the deserializer populated.');

        // Refresh locale on each PATCH: the account preference may have changed since
        // the trip was created.
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $this->tripStateManager->storeLocale($id, $user->getLocale());

        // The precondition also has to guard the write rather than follow it: checked after, a
        // stale If-Match would persist the settings and only then answer 412 — a refusal the
        // caller is entitled to read as "nothing happened".
        //
        // The resolver is the whole answer. It used to sit behind a cached hash of eight
        // fields, which was strictly less precise than the resolver's own field-by-field
        // comparison and wrong in both directions: it omitted `departureHour` and
        // `averageSpeed`, so an edit to either was persisted and never recomputed, and its
        // 30-minute TTL made an identical replay re-trigger the whole pipeline.
        $computationsToTrigger = $this->dependencyResolver->resolve($oldRequest, $data);
        $hasChanged = [] !== $computationsToTrigger;

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

        // Nothing the resolver recognises changed — a title-only edit, or the same body sent
        // twice. The settings are saved above either way; there is simply nothing to recompute.
        if (!$hasChanged) {
            $statuses = $this->computationTracker->getStatuses($id) ?? [];

            return new Trip(
                id: $id,
                computationStatus: $statuses,
                isLocked: $this->tripLocker->isLocked($data),
            );
        }

        if (null !== $generation) {
            foreach ($computationsToTrigger as $computation) {
                $this->computationTracker->resetComputation($id, $computation);
                $this->dispatchComputation($id, $computation, $generation);
            }

            // The bump above invalidated every message in flight, and only the resolver's
            // subset was just re-armed. Everything else that was still running is abandoned:
            // say so, or it stays `pending` and the completion gate never closes again
            // (ADR-073). This processor is where that was worst — the recompute endpoint has
            // always guarded itself, by re-running the whole pipeline instead.
            $this->supersession->settleWhatWasNotRedispatched($id, $computationsToTrigger);
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
