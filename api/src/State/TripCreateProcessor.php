<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
<<<<<<< HEAD
<<<<<<< HEAD
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\Security\Voter\TripVoter;
=======
use App\Entity\UserTrip;
=======
>>>>>>> 0f06fb5 (refactor(security): remove TripOwnershipChecker, replace UserTrip with direct user relation)
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
>>>>>>> 9aa31a5 (feat(security): secure Trip and Stage API endpoints with ownership checks)
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<TripRequest, Trip>
 */
final readonly class TripCreateProcessor implements ProcessorInterface
{
    private const int CACHE_TTL = 1800; // 30 minutes

    public function __construct(
        private MessageBusInterface $messageBus,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private RequestStack $requestStack,
        private TripLocker $tripLocker,
        private Security $security,
<<<<<<< HEAD
=======
        private EntityManagerInterface $entityManager,
>>>>>>> 9aa31a5 (feat(security): secure Trip and Stage API endpoints with ownership checks)
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    /**
     * @param TripRequest $data
     * @param Post        $operation
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        $tripId = Uuid::v7()->toRfc4122();

        // Associate trip with current user before persisting
        /** @var User $user */
        $user = $this->security->getUser();
        $data->user = $user;

        $this->tripStateManager->initializeTrip($tripId, $data);

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($tripId, $locale);

<<<<<<< HEAD
        // Store userId in Redis for fast ownership checks during computation
        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        $item->set($user->getId()->toRfc4122());
        $item->expiresAfter(TripVoter::CACHE_TTL);

        $this->tripStateCache->save($item);
=======
        // Associate trip with current user
<<<<<<< HEAD
        $this->associateTripWithUser($tripId, $data);
>>>>>>> 9aa31a5 (feat(security): secure Trip and Stage API endpoints with ownership checks)
=======
        $this->associateTripWithUser($tripId);
>>>>>>> faa2909 (fix(security): resolve PHPStan errors, Rector fixes, and regenerate TS types)

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        $this->generationTracker->initialize($tripId);
        $generation = 1;

        $this->messageBus->dispatch(new FetchAndParseRoute($tripId, $generation));

        return new Trip(
            id: $tripId,
            computationStatus: $this->buildInitialStatus($computations),
            isLocked: $this->tripLocker->isLocked($data),
        );
    }

    private function associateTripWithUser(string $tripId): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Re-fetch the managed TripRequest entity (initializeTrip may have persisted it)
        $managedTrip = $this->entityManager->getRepository(TripRequest::class)->find(Uuid::fromString($tripId));
        if (!$managedTrip instanceof TripRequest) {
            return;
        }

        $managedTrip->user = $user;
        $this->entityManager->flush();

        // Store userId in Redis for fast ownership checks during computation
        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        $item->set($user->getId()->toRfc4122());
        $item->expiresAfter(self::CACHE_TTL);

        $this->tripStateCache->save($item);
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
