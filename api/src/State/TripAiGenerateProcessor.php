<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Trip;
use App\ApiResource\TripAiGenerateRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Llm\ResolvedLlmClient;
use App\Llm\UserLlmResolverInterface;
use App\Message\GenerateAiRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\Security\Voter\TripVoter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Handles `POST /trips/ai-generate` (B1, ADR-042): creates an empty trip and
 * kicks off async AI route generation.
 *
 * Mirrors {@see TripCreateProcessor} (UUID v7, owner cache, pipeline
 * computations, generation tracker) but dispatches {@see GenerateAiRoute}
 * instead of FetchAndParseRoute — the LLM call + geocoding + Valhalla routing
 * run on the worker so the HTTP request returns immediately (202).
 *
 * @implements ProcessorInterface<TripAiGenerateRequest, Trip>
 */
final readonly class TripAiGenerateProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private TripGenerationTrackerInterface $generationTracker,
        private UserLlmResolverInterface $clientFactory,
        private RequestStack $requestStack,
        private Security $security,
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        #[Autowire(service: 'limiter.ai_generate')]
        private RateLimiterFactory $aiGenerateLimiter,
    ) {
    }

    /**
     * @param TripAiGenerateRequest $data
     * @param Post                  $operation
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Trip
    {
        \assert($data instanceof TripAiGenerateRequest);

        /** @var User $user */
        $user = $this->security->getUser();

        // Fail fast — and before consuming a rate-limit token — when the user has
        // no provider configured: generation is impossible without one.
        if (!$this->clientFactory->forUser($user) instanceof ResolvedLlmClient) {
            throw new UnprocessableEntityHttpException('Configure an AI provider in your settings to generate a route.');
        }

        $limiter = $this->aiGenerateLimiter->create($user->getId()->toRfc4122());
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'AI generation rate limit reached. Please wait a moment before retrying.');
        }

        $tripId = Uuid::v7()->toRfc4122();

        $request = new TripRequest();
        $request->user = $user;

        $this->tripStateManager->initializeTrip($tripId, $request);

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($tripId, $locale);

        // Store userId in Redis for fast ownership checks during computation
        // (the async handler resolves the owner's provider from this key).
        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        $item->set($user->getId()->toRfc4122());
        $item->expiresAfter(TripVoter::CACHE_TTL);

        $this->tripStateCache->save($item);

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        $this->generationTracker->initialize($tripId);
        $generation = 1;

        $this->messageBus->dispatch(new GenerateAiRoute($tripId, $data->brief, $locale, $generation));

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
