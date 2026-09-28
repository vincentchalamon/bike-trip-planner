<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Grants access to trip operations based on ownership.
 *
 * Reads the owner from the PostgreSQL trip table (user column) and nothing else. Every creation
 * path flushes the row, owner included, before answering, so there is no window a cached copy
 * of the owner would cover. The Redis copy that used to back this up was only ever consulted
 * when the database said "not the owner", which in practice meant a deleted trip, and it then
 * reopened that trip to its former owner for the rest of the cache TTL.
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

    private const array SUPPORTED_ATTRIBUTES = [
        self::TRIP_VIEW,
        self::TRIP_EDIT,
        self::TRIP_DELETE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

        return $this->isOwnerInDatabase($user, $tripId);
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
}
