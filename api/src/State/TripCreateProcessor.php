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
        $already = $this->idempotency->alreadyCreated($user, $operation, $context);
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

        // Two flushes, not one: initializeTrip() commits the trip itself, and this commits the
        // key. A process killed between them leaves a trip nothing remembers, so a retry makes a
        // second one — the behaviour of every creation before this existed, not a regression, but
        // not the guarantee either.
        //
        // One transaction around both would close that window and open a worse one: recovering
        // from a concurrent insert means catching the unique violation, and a failed flush inside
        // a wrapping transaction closes the entity manager and marks it rollback-only. Atomicity
        // here costs the concurrency guarantee the unique index exists for.
        //
        // So the order is chosen to fail in the safe direction. Trip first means a crash costs a
        // duplicate; key first would leave a key pointing at a trip that was never committed, and
        // the retry would be handed an identifier for nothing at all.
        $winner = $this->idempotency->remember($user, $operation, Uuid::fromString($tripId), $context);

        // Lost the insert race: a concurrent call carrying this key recorded its own trip first,
        // and the client has to be answered with that one — two requests under one key must never
        // see two identifiers. The trip committed a few lines up stays behind unreachable, which
        // is the same price the crash window charges; its pipeline is at least never dispatched.
        if ($winner->toRfc4122() !== $tripId) {
            return $this->tripFor($winner->toRfc4122());
        }

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
            isLocked: $request instanceof TripRequest && $this->tripLocker->isLocked($request),
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
