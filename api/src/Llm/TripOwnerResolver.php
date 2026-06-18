<?php

declare(strict_types=1);

namespace App\Llm;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the owner (User) of a trip from its id, for async LLM handlers that
 * only carry a tripId and have no security context (ADR-042). Mirrors
 * {@see \App\Security\Voter\TripVoter}: the owner is tracked in Redis during
 * computation (`trip.{id}.user_id`), with the persisted TripRequest as fallback.
 *
 * The User is always reloaded fresh from the database so its current AI token is
 * used — never a stale snapshot from when the trip was created.
 */
final readonly class TripOwnerResolver
{
    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(string $tripId): ?User
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        $item = $this->tripStateCache->getItem(\sprintf('trip.%s.user_id', $tripId));
        if ($item->isHit() && \is_string($userId = $item->get()) && Uuid::isValid($userId)) {
            $user = $this->userRepository->find(Uuid::fromString($userId));
            if ($user instanceof User) {
                return $user;
            }
        }

        return $this->entityManager->find(TripRequest::class, Uuid::fromString($tripId))?->user;
    }
}
