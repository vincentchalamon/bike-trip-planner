<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Grants access to trip operations based on ownership.
 *
 * Checks the PostgreSQL trip table first (user column), with a Redis fallback
 * for trips that are still being computed (not yet persisted).
 *
 * The subject is a trip identity in any of the three shapes the framework hands
 * over: the entity itself, the raw route string, or a `Uuid` — API Platform's
 * `UuidUriVariableTransformer` converts a URI variable whose target identifier is
 * typed `Uuid` (e.g. the `tripId` of TripShare, whose Link points at
 * `TripRequest::$id`). Rejecting that shape would make the voter **abstain**, which
 * denies silently and, through ADR-038, surfaces as a plausible 404 — a
 * misconfiguration indistinguishable from a legitimate refusal.
 *
 * @extends Voter<string, TripRequest|Uuid|string>
 */
final class TripVoter extends Voter
{
    public const string TRIP_VIEW = 'TRIP_VIEW';

    public const string TRIP_EDIT = 'TRIP_EDIT';

    public const string TRIP_DELETE = 'TRIP_DELETE';

    public const int CACHE_TTL = 1800; // 30 minutes

    private const array SUPPORTED_ATTRIBUTES = [
        self::TRIP_VIEW,
        self::TRIP_EDIT,
        self::TRIP_DELETE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.trip_state')]
        private readonly CacheItemPoolInterface $tripStateCache,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && ($subject instanceof TripRequest || $subject instanceof Uuid || \is_string($subject));
    }

    /**
     * @param TripRequest|Uuid|string $subject A TripRequest entity, or a trip ID
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $tripId = match (true) {
            $subject instanceof TripRequest => $subject->id?->toRfc4122() ?? '',
            $subject instanceof Uuid => $subject->toRfc4122(),
            default => $subject,
        };

        if ('' === $tripId) {
            return false;
        }

        return $this->isOwner($user, $tripId);
    }

    private function isOwner(User $user, string $tripId): bool
    {
        // Primary check: PostgreSQL TripRequest.user column
        if ($this->isOwnerInDatabase($user, $tripId)) {
            return true;
        }

        // Fallback: Redis (for trips still being computed, not yet in DB)
        return $this->isOwnerInRedis($user, $tripId);
    }

    private function isOwnerInDatabase(User $user, string $tripId): bool
    {
        if (!Uuid::isValid($tripId)) {
            return false;
        }

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(TripRequest::class, 't')
            ->where('t.id = :tripId')
            ->andWhere('t.user = :user')
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private function isOwnerInRedis(User $user, string $tripId): bool
    {
        $key = \sprintf('trip.%s.user_id', $tripId);
        $item = $this->tripStateCache->getItem($key);

        if (!$item->isHit()) {
            return false;
        }

        return $item->get() === $user->getId()->toRfc4122();
    }
}
