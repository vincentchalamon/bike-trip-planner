<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthGrant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<OAuthGrant>
 */
final class OAuthGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthGrant::class);
    }

    /**
     * The applications that can still act, with the dates only this table knows.
     *
     * A grant row is not evidence of access — nothing sweeps it, so after a month of silence it
     * would still read "authorised" for an application whose every token has expired. What
     * makes it true is a live token, and this is where the two records meet: the row supplies
     * `authorizedAt` and `lastUsedAt`, the token supplies the right to be listed at all.
     *
     * "Live" has to include refresh tokens. An access token lives fifteen minutes, so an
     * access-only test would drop every application a quarter of an hour after its last call
     * while it still holds a month of refresh. Refresh tokens carry neither user nor client —
     * they are reachable only through `accessToken` — hence the nested subquery.
     *
     * ⚠ That link is `ON DELETE SET NULL`: if `league:oauth2-server:clear-expired-tokens` is
     * ever scheduled, it nulls the reference before deleting expired access tokens, and a live
     * refresh token becomes invisible here (and unrevokable in {@see \App\Security\OAuth\GrantRevoker}).
     * Nothing schedules it today. Fixing that sweep comes before scheduling it.
     *
     * @return list<OAuthGrant>
     */
    public function findLiveForUser(User $user): array
    {
        $now = new \DateTimeImmutable();

        // Correlated on `g.client`, the outer row — not on a parameter. The two token tables
        // are keyed by the email, this one by the user, so the join between them is spelled out
        // rather than inferred.
        $liveAccess = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(AccessToken::class, 'at')
            ->where('at.userIdentifier = :email')
            ->andWhere('at.client = g.client')
            ->andWhere('at.revoked = false')
            ->andWhere('at.expiry > :now')
            ->getDQL();

        $liveRefresh = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(RefreshToken::class, 'rt')
            ->join('rt.accessToken', 'rat')
            ->where('rat.userIdentifier = :email')
            ->andWhere('rat.client = g.client')
            ->andWhere('rt.revoked = false')
            ->andWhere('rt.expiry > :now')
            ->getDQL();

        /** @var list<OAuthGrant> $grants */
        $grants = $this->createQueryBuilder('g')
            ->join('g.client', 'client')
            ->addSelect('client')
            ->where('g.user = :user')
            ->andWhere('g.revokedAt IS NULL')
            ->andWhere(\sprintf('EXISTS (%s) OR EXISTS (%s)', $liveAccess, $liveRefresh))
            ->setParameter('user', $user)
            ->setParameter('email', $user->getUserIdentifier())
            ->setParameter('now', $now)
            ->orderBy('g.authorizedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();

        return $grants;
    }

    /**
     * Erasure: the rows go, they are not marked.
     *
     * The tombstone a revocation leaves exists to stop a refresh in flight from recreating a
     * row that was just revoked. An erased account has no refresh in flight and no screen to
     * protect; what would survive is a record of which third parties an anonymised account once
     * trusted, which is the kind of thing erasure is for.
     */
    public function removeAllForUser(User $user): void
    {
        $this->createQueryBuilder('g')
            ->delete()
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * One live grant of this user, by id — scoped rather than fetched-then-checked.
     *
     * Someone else's identifier and an identifier that never existed are the same answer here,
     * because the query cannot see the difference. That is the shape ADR-038 asks for, and the
     * reason {@see \App\State\Account\DeviceTokenDeleteProcessor} does the same: an ownership
     * comparison written in a processor is one someone can forget.
     */
    public function findLiveOwnedBy(User $user, Uuid $id): ?OAuthGrant
    {
        /** @var OAuthGrant|null $grant */
        $grant = $this->createQueryBuilder('g')
            ->where('g.id = :id')
            ->andWhere('g.user = :user')
            ->andWhere('g.revokedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $grant;
    }
}
