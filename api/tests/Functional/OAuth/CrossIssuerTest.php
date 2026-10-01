<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use App\Tests\ApiTestCase;
use App\Tests\Functional\JwtAuthTestTrait;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Neither issuer's token opens the other's door — the half that was missing (#1309).
 *
 * `McpToolCallTest::aPwaSessionTokenOpensNothingHere` pins one direction: a PWA session JWT
 * gets 401 on `/mcp`. Nothing pinned the mirror image, which is the one an agent could
 * actually exploit: an access token minted for `/mcp` must not reach the REST API, where
 * every trip of every account lives behind the same Bearer header.
 *
 * ⚠ **This does not prove the keys are what separates them**, and must not be read as if it
 * did. Two independent barriers stand in this direction, and either alone would produce the
 * 401 below:
 *
 *  - the **signature**: the `api` firewall authenticates with Lexik, which verifies against
 *    the JWT public key, and an agent token is signed with the OAuth private key (ADR-079);
 *  - the **claim name**: Lexik reads the user out of `username` (its `user_id_claim` default),
 *    while `league/oauth2-server` writes the subject into `sub`
 *    ({@see \League\OAuth2\Server\Entities\Traits\AccessTokenTrait}). Even with one shared
 *    key, the lookup would find nobody.
 *
 * So what this pins is the **guarantee**, not the mechanism — and it is held up by its two
 * controls rather than by a clean sabotage: the session token opens the same route, and the
 * same agent token opens `/mcp`, so the 401 can be blamed on neither the route, the account,
 * the fixture nor a broken token.
 *
 * There is no clean sabotage, and that is worth recording. Every way of removing one of the
 * two barriers was tried and each one takes the token-issuing flow down with it: `oauth2: true`
 * on the `api` firewall (whose pattern is `^/`) puts an OAuth authenticator in front of
 * `/oauth/token`, and pointing Lexik at the OAuth public key breaks the session JWT the
 * consent step is approved with. The separation cannot be dismantled by halves — which is a
 * property of the design, not an excuse for a weak test, and is why the controls above carry
 * the weight here.
 */
#[ResetDatabase]
final class CrossIssuerTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    #[Test]
    public function anAgentAccessTokenOpensNothingOnTheRestApi(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        ['user' => $user, 'jwt' => $sessionJwt] = $this->createAuthenticatedUser('both-ways@example.com');
        $accessToken = $this->issueAccessTokenFor($user, ['trips:read', 'trips:write']);

        // The control: the session token this account really holds does open the REST API. It
        // is here so a 401 below cannot be blamed on the route, the account or the fixture.
        $allowed = self::createClient()->request('GET', '/trips', [
            'headers' => ['Authorization' => 'Bearer '.$sessionJwt, 'Accept' => 'application/ld+json'],
        ]);
        self::assertSame(200, $allowed->getStatusCode(), 'The session token should open the REST API.');

        $refused = self::createClient()->request('GET', '/trips', [
            'headers' => ['Authorization' => 'Bearer '.$accessToken, 'Accept' => 'application/ld+json'],
        ]);
        self::assertSame(401, $refused->getStatusCode(), 'An agent access token must not open the REST API.');
    }

    /**
     * The same token, on the endpoint it WAS issued for, works.
     *
     * Without this, the assertion above would also pass for a token that is simply broken —
     * a truncated string, a flow that silently failed — and would prove nothing at all.
     */
    #[Test]
    public function theSameTokenWorksOnTheEndpointItWasIssuedFor(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        ['user' => $user] = $this->createAuthenticatedUser('still-valid@example.com');
        $accessToken = $this->issueAccessTokenFor($user, ['trips:read']);

        // Shaped like McpToolCallTest builds one: the 2026-07-28 revision mirrors the
        // protocol version and the method into headers AND into `params._meta`, and the
        // modern leg answers -32020 when they are missing.
        $response = self::createClient()->request('POST', '/mcp', [
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

        self::assertSame(200, $response->getStatusCode(), 'The token must still be a working agent token.');
    }
}
