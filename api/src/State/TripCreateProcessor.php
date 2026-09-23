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
use App\Entity\User;
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\Security\Voter\TripVoter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private TripGenerationTrackerInterface $generationTracker,
        private TripLocker $tripLocker,
        private Security $security,
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        #[Autowire(service: 'limiter.trip_create')]
        private RateLimiterFactory $tripCreateLimiter,
        private Idempotency $idempotency,
    ) {
    }

    /**
     * @param TripRequest $data
     * @param Post        $operation
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        /** @var User $user */
        $user = $this->security->getUser();

        // Before the limiter and before any work: a retry of a creation that already succeeded
        // must cost nothing and answer with the trip it made, not with a second one (ADR-077).
        $already = $this->idempotency->alreadyCreated($user, TripCreation::REQUIRES_IDEMPOTENCY_KEY);
        if ($already instanceof Uuid) {
            return $this->tripFor($already->toRfc4122());
        }

        $limiter = $this->tripCreateLimiter->create($user->getId()->toRfc4122());

        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $tripId = Uuid::v7()->toRfc4122();

        // Associate trip with current user before persisting
        $data->user = $user;

        $this->tripStateManager->initializeTrip($tripId, $data);

        $this->tripStateManager->storeLocale($tripId, $user->getLocale());

        // Store userId in Redis for fast ownership checks during computation
        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        $item->set($user->getId()->toRfc4122());
        $item->expiresAfter(TripVoter::CACHE_TTL);

        $this->tripStateCache->save($item);

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        $this->generationTracker->initialize($tripId);
        $generation = 1;

        // Recorded in the same unit of work as the trip, before the pipeline is dispatched: a
        // crash between the two leaves a key pointing at a trip whose computations never
        // started, which `/recompute` can settle. The reverse — a trip with no key — would let
        // the retry make a second one.
        $this->idempotency->remember($user, TripCreation::REQUIRES_IDEMPOTENCY_KEY, Uuid::fromString($tripId));

        $this->messageBus->dispatch(new FetchAndParseRoute($tripId, $generation));

        return new Trip(
            id: $tripId,
            computationStatus: $this->buildInitialStatus($computations),
            isLocked: $this->tripLocker->isLocked($data),
        );
    }

    /**
     * The answer the first call produced, rebuilt rather than remembered: storing the serialised
     * body would mean versioning that serialisation for as long as the keys live, and this gives
     * the caller the statuses as they stand now instead of a snapshot of the instant of creation.
     */
    private function tripFor(string $tripId): Trip
    {
        $request = $this->tripStateManager->getRequest($tripId);

        return new Trip(
            id: $tripId,
            computationStatus: $this->computationTracker->getStatuses($tripId) ?? [],
            isLocked: null !== $request && $this->tripLocker->isLocked($request),
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
            $status[$computation->value] = ComputationStatus::PENDING->value;
        }

        return $status;
    }
}
