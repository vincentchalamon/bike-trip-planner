<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripBatchRecomputeRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\ComputationDependencyResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Processes the batch recompute endpoint: applies N pending modifications in a
 * single request, dispatching only the minimal set of handlers needed.
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
