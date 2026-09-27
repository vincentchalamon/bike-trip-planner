<?php

declare(strict_types=1);

namespace App\Security;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\MagicLinkRepository;
use App\Repository\OAuthGrantRepository;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;

/**
 * Everything a GDPR erasure destroys, in one transaction and in one order (#1309).
 *
 * It lives here rather than in the processor because **the order is the interesting part**,
 * and the order spans the whole list: `revokeCredentialsForUser()` has to run before
 * `anonymize()`, so a unit that owned only the revocations would own half a constraint and
 * leave the other half somewhere a future edit could move. Whatever else this class is, it is
 * the place where that sequence can be read in one go.
 *
 * Two token systems are revoked here, deliberately not merged (ADR-079): the PWA's own refresh
 * tokens, and the credentials `league/oauth2-server` issued to agents. Plus the durable grant
 * rows behind the latter (ADR-082).
 *
 * The caller keeps the HTTP: resolving the user, clearing the cookie, the 204, the audit log.
 */
final readonly class AccountEraser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RefreshTokenRepository $refreshTokenRepository,
        private MagicLinkRepository $magicLinkRepository,
        private AccessRequestRepository $accessRequestRepository,
        private CredentialsRevokerInterface $credentialsRevoker,
        private OAuthGrantRepository $oauthGrants,
    ) {
    }

    /**
     * Soft-deletes the account (stamps `deletedAt`), irreversibly anonymises the email to break
     * the PII link, purges every trip — and, via cascade, their stages and per-trip preferences
     * — and takes back every credential the account ever handed out.
     */
    public function erase(User $user): void
    {
        // Captured before anonymisation: the standalone access_request rows hold the email as
        // PII with no user FK, so they can only be found by the value anonymize() is about to
        // rewrite.
        $email = $user->getEmail();

        $this->entityManager->wrapInTransaction(function () use ($user, $email): void {
            // Purge trips (cascades to stages and shares via FK ON DELETE CASCADE) which also
            // removes the per-trip preferences.
            $this->entityManager->createQueryBuilder()
                ->delete(TripRequest::class, 't')
                ->where('t.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->execute();

            // Revoke every refresh token so lingering sessions cannot be reused.
            $this->refreshTokenRepository->removeAllForUser($user);

            // Purge magic links: the soft-delete below does not trigger the FK ON DELETE
            // CASCADE, so a lingering valid link could otherwise still authenticate the (now
            // anonymised) account.
            $this->magicLinkRepository->removeAllForUser($user);

            // Purge early-access requests holding the email/IP PII (standalone table, no user FK).
            $this->accessRequestRepository->removeAllForEmail($email);

            // Revoke every OAuth grant: an agent authorised by this account stops being able to
            // act for it (ADR-079).
            //
            // ⚠ BEFORE anonymize(), and nothing would tell you at runtime if it were after. The
            // revoker filters on getUserIdentifier(), which is the email — the one thing
            // anonymize() rewrites. Run afterwards, its four UPDATEs all succeed and all touch
            // zero rows. The symptom is a row count, which is why
            // AccountErasureRevokesAgentsTest asserts one: no HTTP response can tell the two
            // apart, since an anonymised account is refused by the firewall either way.
            $this->credentialsRevoker->revokeCredentialsForUser($user);

            // And the rows that remembered who was let in, when. Deleted rather than marked
            // revoked, unlike a revocation from the account page: the tombstone there guards
            // against a refresh in flight recreating the row, and an erased account has no
            // refresh left to guard against. What would remain is a record tying an anonymised
            // account to the third parties it once trusted (ADR-082).
            //
            // ⚠ Unlike the revocation above, this one does NOT depend on the ordering: grants
            // key on the user, not on the email anonymize() rewrites. Moving it after would be
            // harmless — moving the line above would not.
            $this->oauthGrants->removeAllForUser($user);

            // Soft-delete + irreversible PII anonymisation.
            $user->anonymize();
        });
    }
}
