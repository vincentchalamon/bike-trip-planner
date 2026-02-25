<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationDependencyResolver;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\CheckCalendar;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
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

        // Provider (TripRequestProvider) already threw 404 if the trip doesn't exist;
        // the processor only runs when $data is a valid, non-null TripRequest.
        $oldRequest = $this->tripStateManager->getRequest($id);
        \assert($oldRequest instanceof TripRequest);

        // Check idempotency
        if (!$this->idempotencyChecker->hasChanged($id, $data)) {
            $statuses = $this->computationTracker->getStatuses($id) ?? [];

            return new Trip(
                id: $id,
                computationStatus: $statuses,
            );
        }

        // Persist updated request
        $this->tripStateManager->storeRequest($id, $data);
        $this->idempotencyChecker->saveHash($id, $data);

        // Determine which computations to re-trigger
        $computationsToTrigger = $this->dependencyResolver->resolve($oldRequest, $data);

        foreach ($computationsToTrigger as $computation) {
            $this->computationTracker->resetComputation($id, $computation);
            $this->dispatchComputation($id, $computation);
        }

        $statuses = $this->computationTracker->getStatuses($id) ?? [];

        return new Trip(
            id: $id,
            computationStatus: $statuses,
        );
    }

    private function dispatchComputation(string $tripId, ComputationName $computation): void
    {
        match ($computation) {
            ComputationName::ROUTE => $this->messageBus->dispatch(new FetchAndParseRoute($tripId)),
            ComputationName::STAGES => $this->messageBus->dispatch(new GenerateStages($tripId)),
            ComputationName::WEATHER => $this->messageBus->dispatch(new FetchWeather($tripId)),
            ComputationName::CALENDAR => $this->messageBus->dispatch(new CheckCalendar($tripId)),
            // These computations are cascaded internally by their parent handlers,
            // not dispatched directly as root computations from a PATCH operation.
            // If a new ComputationName appears here unexpectedly, fail-fast to surface the gap.
            default => throw new \LogicException(\sprintf('No direct dispatch registered for computation "%s" in %s. Add it to PARAMETER_DEPENDENCIES or wire its dispatch here.', $computation->value, self::class)),
        };
    }
}
