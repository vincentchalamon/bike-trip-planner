<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<TripRequest, Trip>
 */
final readonly class TripCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param TripRequest $data
     * @param Post        $operation
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->tripStateManager->initializeTrip($tripId, $data);

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($tripId, $locale);

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        $this->messageBus->dispatch(new FetchAndParseRoute($tripId));

        return new Trip(
            id: $tripId,
            computationStatus: $this->buildInitialStatus($computations),
        );
    }

    /**
     * @param list<ComputationName> $computations
     *
     * @return array<string, string>
     */
    private function buildInitialStatus(array $computations): array
    {
        $status = [];
        foreach ($computations as $computation) {
            $status[$computation->value] = 'pending';
        }

        return $status;
    }
}
