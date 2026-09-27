<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthGrant;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * Takes back what one user gave to one application — and nothing else.
 *
 * The bundle offers `CredentialsRevokerInterface::revokeCredentialsForClient()`, and the ticket
 * that asked for this screen named it. It cannot be used: it filters on the client alone, so
 * called from an account page it would cut that application off for **every** user of it. The
 * other method, `revokeCredentialsForUser()`, is the opposite blunt instrument — it is what
 * account deletion wants, not what a user unticking one application wants.
 *
 * So the intersection is written here, once, and asserted by a test with two users sharing a
 * client rather than by a comment asking future readers to be careful.
 *
 * Three statements, in the order the tables allow:
 *
 *   1. the access tokens of this user for this client;
 *   2. the refresh tokens hanging off them — `oauth2_refresh_token` carries neither user nor
 *      client, only a nullable link to the access token, so the client goes in the subquery;
 *   3. the authorization codes, same intersection, for a code issued and not yet exchanged.
 *
 * No device codes: that grant is disabled and the entity is therefore unmapped, so a DQL
 * statement against it is a hard error rather than a no-op.
 *
 * ⚠ Step 2 inherits the weakness of that nullable link (`ON DELETE SET NULL`). If the bundle's
 * expired-token sweep is ever scheduled, it nulls the reference before deleting expired access
 * tokens, and a refresh token with weeks of life left becomes unreachable from here — revoked
 * in appearance, alive in fact. Nothing schedules it today, and `revokeCredentialsForUser()`
 * has the same hole, which means account deletion does too. That is a fix to make before
 * scheduling the sweep, not after.
 */
final readonly class GrantRevoker
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function revoke(OAuthGrant $grant): void
    {
        $email = $grant->getUser()->getUserIdentifier();
        $client = $grant->getClient()->getIdentifier();

        $this->entityManager->wrapInTransaction(function () use ($grant, $email, $client): void {
            $this->entityManager->createQueryBuilder()
                ->update(AccessToken::class, 'at')
                ->set('at.revoked', ':revoked')
                ->where('at.userIdentifier = :email')
                ->andWhere('at.client = :client')
                ->setParameter('revoked', true)
                ->setParameter('email', $email)
                ->setParameter('client', $client)
                ->getQuery()
                ->execute();

            $inner = $this->entityManager->createQueryBuilder()
                ->select('inner_at.identifier')
                ->from(AccessToken::class, 'inner_at')
                ->where('inner_at.userIdentifier = :email')
                ->andWhere('inner_at.client = :client')
                ->getDQL();

            $refreshTokens = $this->entityManager->createQueryBuilder();
            $refreshTokens
                ->update(RefreshToken::class, 'rt')
                ->set('rt.revoked', ':revoked')
                ->where($refreshTokens->expr()->in('rt.accessToken', $inner))
                ->setParameter('revoked', true)
                ->setParameter('email', $email)
                ->setParameter('client', $client)
                ->getQuery()
                ->execute();

            $this->entityManager->createQueryBuilder()
                ->update(AuthorizationCode::class, 'ac')
                ->set('ac.revoked', ':revoked')
                ->where('ac.userIdentifier = :email')
                ->andWhere('ac.client = :client')
                ->setParameter('revoked', true)
                ->setParameter('email', $email)
                ->setParameter('client', $client)
                ->getQuery()
                ->execute();

            // A tombstone, not a delete: a refresh already in flight re-issues a token a
            // moment later, and the write at issuance would recreate a deleted row with a
            // brand-new date — the application the user just cut off, listed as freshly
            // authorised. The partial unique index leaves room for a real re-authorisation
            // beside this one.
            $this->entityManager->createQueryBuilder()
                ->update(OAuthGrant::class, 'g')
                ->set('g.revokedAt', ':now')
                ->where('g.id = :id')
                ->setParameter('now', new \DateTimeImmutable())
                ->setParameter('id', $grant->getId())
                ->getQuery()
                ->execute();
        });
    }
}
