<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use App\Tests\ApiTestCase;
use App\Tests\Functional\JwtAuthTestTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Deleting an account takes the agents with it (ADR-079).
 *
 * "Account deleted, therefore agents revoked" is a requirement of the programme, and it has
 * an ordering trap with no symptom: the revoker filters on `getUserIdentifier()`, which is
 * the email, and `anonymize()` rewrites exactly that. Called afterwards, its four UPDATEs
 * all succeed and all touch zero rows — a green deletion leaving live tokens behind.
 */
#[ResetDatabase]
final class AccountErasureRevokesAgentsTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    #[Test]
    public function erasingAnAccountRevokesTheTokensItGaveAway(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        ['user' => $user, 'token' => $sessionJwt] = $this->createTestUserWithJwt('leaving@example.com');
        $this->issueAccessTokenFor($user);

        self::assertSame(0, $this->revokedTokenCount(), 'Nothing should be revoked yet.');
        self::assertSame(1, $this->tokenCount(), 'The flow did not record an access token.');

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$sessionJwt],
        ]);

        self::assertResponseStatusCodeSame(204);
        // Counted on the row rather than on the call succeeding: the ordering bug this
        // guards against is one where the call succeeds and changes nothing.
        self::assertSame(1, $this->revokedTokenCount());
    }

    private function tokenCount(): int
    {
        return $this->rowsMatching('SELECT COUNT(*) FROM oauth2_access_token WHERE user_identifier = ?');
    }

    private function revokedTokenCount(): int
    {
        return $this->rowsMatching('SELECT COUNT(*) FROM oauth2_access_token WHERE user_identifier = ? AND revoked = true');
    }

    private function rowsMatching(string $sql): int
    {
        $count = $this->connection()->fetchOne($sql, ['leaving@example.com']);
        self::assertIsNumeric($count);

        return max(0, (int) $count);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
