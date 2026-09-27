<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use App\Tests\ApiTestCase;
use App\Tests\Functional\JwtAuthTestTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Deleting an account takes the agents with it (ADR-079).
 *
 * "Account deleted, therefore agents revoked" is a requirement of the programme, and it has
 * an ordering trap with no symptom: the revoker filters on `getUserIdentifier()`, which is
 * the email, and `anonymize()` rewrites exactly that. Called afterwards, its four UPDATEs
 * all succeed and all touch zero rows — a green deletion leaving live tokens behind.
 *
 * Which is why **the row count is the isolating assertion here, and the behavioural one is
 * not**. After an erasure an agent token is refused by `/mcp` for two reasons that have
 * nothing to do with the revocation: `anonymize()` rewrites the email the token carries as
 * its `user_identifier`, so the provider loads nobody, and `DeletedUserChecker` — wired as
 * the `mcp` firewall's `user_checker` — rejects a deleted account outright. Delete the
 * revocation entirely and the HTTP assertion below stays green. It is kept because it pins
 * what a user is promised; it is labelled because it cannot pin how that promise is kept.
 */
#[ResetDatabase]
final class AccountErasureRevokesAgentsTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    #[Test]
    public function erasingAnAccountRevokesTheTokensItGaveAway(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        ['user' => $user, 'token' => $sessionJwt] = $this->createTestUserWithJwt('leaving@example.com');
        $accessToken = $this->issueAccessTokenFor($user);

        self::assertSame(200, $this->toolsList($accessToken)->getStatusCode(), 'The agent should be able to act before the erasure.');

        self::assertSame(0, $this->revokedTokenCount(), 'Nothing should be revoked yet.');
        self::assertSame(1, $this->tokenCount(), 'The flow did not record an access token.');

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$sessionJwt],
        ]);

        self::assertResponseStatusCodeSame(204);
        // Counted on the row rather than on the call succeeding: the ordering bug this
        // guards against is one where the call succeeds and changes nothing.
        self::assertSame(1, $this->revokedTokenCount());

        // And the record of who had been let in goes with it (#1308). Deleted, not marked:
        // the tombstone a revocation from the account page leaves exists to survive a refresh
        // in flight, and an erased account has none — what would survive instead is a list of
        // the third parties an anonymised account once trusted.
        self::assertSame(0, $this->grantCount());

        // The promise, from where a user stands. NOT isolating — see the class docblock: two
        // other mechanisms would produce this 401 on their own. Here to say what is owed, not
        // to prove who pays it.
        self::assertSame(401, $this->toolsList($accessToken)->getStatusCode(), 'The agent must no longer be able to act.');
    }

    /**
     * The cheapest call an agent can make, shaped for the 2026-07-28 revision: the protocol
     * version and the method are mirrored into headers and into `params._meta`, and the
     * modern leg answers -32020 when they are missing.
     */
    private function toolsList(string $accessToken): ResponseInterface
    {
        return self::createClient()->request('POST', '/mcp', [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/list',
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function grantCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM oauth_grant');
        self::assertIsNumeric($count);

        return (int) $count;
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
