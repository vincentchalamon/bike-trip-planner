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
use App\Enum\ComputationName;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\Security\Voter\TripVoter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private RequestStack $requestStack,
        private TripLocker $tripLocker,
        private Security $security,
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        #[Autowire(service: 'limiter.trip_create')]
        private RateLimiterFactory $tripCreateLimiter,
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
        $limiter = $this->tripCreateLimiter->create($user->getId()->toRfc4122());

        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $tripId = Uuid::v7()->toRfc4122();

        // Associate trip with current user before persisting
        $data->user = $user;

        $this->tripStateManager->initializeTrip($tripId, $data);

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($tripId, $locale);

        // Store userId in Redis for fast ownership checks during computation
        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        $item->set($user->getId()->toRfc4122());
        $item->expiresAfter(TripVoter::CACHE_TTL);

        $this->tripStateCache->save($item);

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
