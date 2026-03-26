<?php

declare(strict_types=1);

namespace App\State;

use LogicException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationDependencyResolver;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\CheckCalendar;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private RequestStack $requestStack,
        private TripLocker $tripLocker,
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

        // Refresh locale on each PATCH
        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($id, $locale);

        // Provider (TripRequestProvider) already threw 404 if the trip doesn't exist;
        // the processor only runs when $data is a valid, non-null TripRequest.
        // Reuse the request already fetched above for the lock check.
        $oldRequest = $existingRequest;

        // Always persist — non-computation fields (e.g. title) may have changed
        $this->tripStateManager->storeRequest($id, $data);

        // Check idempotency for computation-triggering fields only
        if (!$this->idempotencyChecker->hasChanged($id, $data)) {
            $statuses = $this->computationTracker->getStatuses($id) ?? [];

            return new Trip(
                id: $id,
                computationStatus: $statuses,
                isLocked: $this->tripLocker->isLocked($existingRequest),
            );
        }

        $this->idempotencyChecker->saveHash($id, $data);

        // Determine which computations to re-trigger
        $computationsToTrigger = $this->dependencyResolver->resolve($oldRequest, $data);

        if ([] !== $computationsToTrigger) {
            // Criteria changed: bump generation so in-flight messages become stale
            $generation = $this->generationTracker->increment($id);

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
        match ($computation) {
            ComputationName::ROUTE => $this->messageBus->dispatch(new FetchAndParseRoute($tripId, $generation)),
            ComputationName::STAGES => $this->messageBus->dispatch(new GenerateStages($tripId, $generation)),
            ComputationName::TERRAIN => $this->messageBus->dispatch(new AnalyzeTerrain($tripId, $generation)),
            ComputationName::WEATHER => $this->messageBus->dispatch(new FetchWeather($tripId, $generation)),
            ComputationName::CALENDAR => $this->messageBus->dispatch(new CheckCalendar($tripId, $generation)),
            ComputationName::ACCOMMODATIONS => $this->dispatchAccommodationsScan($tripId, $generation),
            // These computations are cascaded internally by their parent handlers,
            // not dispatched directly as root computations from a PATCH operation.
            // If a new ComputationName appears here unexpectedly, fail-fast to surface the gap.
            default => throw new LogicException(\sprintf('No direct dispatch registered for computation "%s" in %s. Add it to PARAMETER_DEPENDENCIES or wire its dispatch here.', $computation->value, self::class)),
        };
    }

    private function dispatchAccommodationsScan(string $tripId, int $generation): void
    {
        $request = $this->tripStateManager->getRequest($tripId);
        \assert($request instanceof TripRequest);

        $this->messageBus->dispatch(new ScanAccommodations(
            $tripId,
            enabledAccommodationTypes: $request->enabledAccommodationTypes,
            generation: $generation,
        ));
    }
}
