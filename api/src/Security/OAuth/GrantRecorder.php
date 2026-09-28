<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Psr\Clock\ClockInterface;

/**
 * Writes down that an application was let in, at the one moment that proves it was.
 *
 * Called from {@see AudienceBoundAccessTokenRepository::persistNewAccessToken()}, which is the
 * only place a token is actually issued. The two seats that look better are worse: the bundle's
 * `AccessTokenManager::save()` is *also* its revocation hook — a refresh calls it twice, once
 * with a revoked token — and `ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE` is dead here, because the
 * decorator above replaces the very method that dispatches it. A listener on it would never run
 * and nothing would say so.
 *
 * Consent is not the moment either: approving without completing the code exchange would leave
 * a row for an application that holds nothing.
 *
 * ## Why raw SQL rather than the ORM
 *
 * The token has already been flushed when this runs. Two code exchanges racing for the same
 * pair would then hit the unique index inside the entity manager, close it, and answer 500 on
 * `/oauth/token` — an agent that cannot connect, because of a row that only decorates a screen.
 * `ON CONFLICT` makes the collision the normal path instead of an exception.
 *
 * `DO UPDATE SET scopes` rather than `DO NOTHING`, and that is not a detail: a second
 * authorisation that widens the permissions (`trips:read` then `trips:read trips:write`) would
 * otherwise leave the first row untouched, and the account screen would show **less** power
 * than the application holds. `authorized_at` stays the first one — the day the user opened the
 * door is the day they opened it.
 */
final readonly class GrantRecorder
{
    public function __construct(
        private Connection $connection,
        private UserRepository $users,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function record(string $userIdentifier, string $clientIdentifier, array $scopes): void
    {
        $user = $this->users->findByEmail($userIdentifier);

        if (!$user instanceof User) {
            // Not theoretical: a refresh replays the email carried in the old token's payload
            // (RefreshTokenGrant reads `user_id` from it, never the user row), so an address
            // changed in between resolves to nobody. The email change revokes those tokens for
            // exactly this reason — this branch is the belt, and it never fails an issuance.
            $this->logger->info('No account for the identifier a token was issued to; no grant recorded.', [
                'client' => $clientIdentifier,
            ]);

            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO oauth_grant (id, user_id, client_identifier, scopes, authorized_at)
                VALUES (:id, :user, :client, :scopes, :now)
                ON CONFLICT (user_id, client_identifier) WHERE revoked_at IS NULL
                DO UPDATE SET scopes = EXCLUDED.scopes
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $user->getId()->toRfc4122(),
                'client' => $clientIdentifier,
                'scopes' => implode(' ', $scopes),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
