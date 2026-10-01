<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use ApiPlatform\Test\Client;
use App\Entity\EmailChangeToken;
use App\Entity\User;
use App\Tests\ApiTestCase;
use App\Tests\Functional\JwtAuthTestTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What a user can see of the agents they let in, and what taking it back actually does.
 *
 * The screen these operations feed is the other half of the consent screen: ADR-079 shipped a
 * door that only opened. What is asserted here is not that a list renders — it is that the
 * facts behind it are the ones the tokens tell, and that revoking is a fact about access rather
 * than a row disappearing.
 */
#[ResetDatabase]
final class AuthorizedApplicationsTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private User $owner;

    private string $ownerJwt;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        ['user' => $this->owner, 'jwt' => $this->ownerJwt] = $this->createAuthenticatedUser('owner@example.com');
    }

    /**
     * Issuing the token is what records the grant — not approving the consent.
     *
     * A user who approves and never completes the code exchange has let nothing in, and a row
     * for an application that holds no token would be a screen inviting them to revoke thin
     * air.
     */
    #[Test]
    public function issuingATokenRecordsTheApplication(): void
    {
        $this->issueAccessTokenFor($this->owner, ['trips:read']);

        $rows = $this->grantRows();
        self::assertCount(1, $rows);
        self::assertSame('trips:read', $rows[0]['scopes']);
        self::assertNull($rows[0]['revoked_at']);
        self::assertNull($rows[0]['last_used_at'], 'A token that was issued has not been used yet.');
    }

    /**
     * The one the review of this unit would have missed: a second authorisation that widens the
     * permissions must widen the row.
     *
     * With `DO NOTHING` on the conflict, the first row survives untouched and the screen shows
     * `trips:read` for an application that can write. Under-reporting power is the one direction
     * this screen must never be wrong in.
     */
    #[Test]
    public function authorizingAgainWithMoreScopesWidensTheRowAndKeepsTheFirstDate(): void
    {
        $this->issueAccessTokenFor($this->owner, ['trips:read']);
        $first = $this->grantRows()[0];

        // The token exchange clears the entity manager, so the user held since setUp() is
        // detached by now; issuing a second time with it persists a new one through the
        // cascade. Re-read it, as every other test that issues twice has to.
        $this->issueAccessTokenFor($this->reloadOwner(), ['trips:read', 'trips:write']);

        $rows = $this->grantRows();
        self::assertCount(1, $rows, 'A second authorisation of the same application is the same grant.');
        self::assertSame('trips:read trips:write', $rows[0]['scopes']);
        self::assertSame($first['authorized_at'], $rows[0]['authorized_at'], 'The day the door was opened does not move.');
    }

    /**
     * The assertion the whole unit exists for, and the one the repository has never had.
     *
     * `AccountErasureRevokesAgentsTest` stops at a row count — it checks that a column flipped,
     * not that anything stopped working. A revocation is a fact about access, so it is asserted
     * on the call: the token that worked a moment ago is refused now.
     */
    #[Test]
    public function revokingStopsTheTokenFromWorking(): void
    {
        $token = $this->issueAccessTokenFor($this->owner, ['trips:read']);

        self::assertSame(200, $this->callMcp($token)->getStatusCode(), 'The token should work before it is revoked.');

        $applications = $this->list();
        self::assertCount(1, $applications);

        $this->revoke($applications[0]['id']);

        self::assertSame(401, $this->callMcp($token)->getStatusCode(), 'A revoked token must stop opening /mcp.');
        self::assertSame([], $this->list(), 'And the application it belonged to must leave the list.');
    }

    /**
     * Revoking one user's grant leaves the other's alone.
     *
     * This is the entire reason the bundle's `revokeCredentialsForClient()` is off-limits: it
     * filters on the client and would cut this application off for everyone who uses it. That
     * is too easy to reintroduce for a comment to be the guard.
     */
    #[Test]
    public function revokingOneUserLeavesAnotherUsersAccessAlone(): void
    {
        $neighbour = $this->createAuthenticatedUser('neighbour@example.com')['user'];
        $neighbourToken = $this->issueAccessTokenFor($neighbour, ['trips:read']);

        $mine = $this->issueAccessTokenFor($this->reloadOwner(), ['trips:read']);

        $this->revoke($this->list()[0]['id']);

        self::assertSame(401, $this->callMcp($mine)->getStatusCode());
        self::assertSame(200, $this->callMcp($neighbourToken)->getStatusCode(), 'The same application, another user: untouched.');
    }

    /** A grant that is not mine answers exactly like one that never existed. */
    #[Test]
    public function anotherUsersGrantIsIndistinguishableFromNoGrantAtAll(): void
    {
        $neighbour = $this->createAuthenticatedUser('neighbour@example.com')['user'];
        $this->issueAccessTokenFor($neighbour, ['trips:read']);

        $theirs = $this->grantRows()[0]['id'];
        self::assertIsString($theirs);

        $foreign = $this->client()->request('DELETE', '/users/me/authorized-applications/'.$theirs, [
            'headers' => ['Authorization' => 'Bearer '.$this->ownerJwt],
        ]);
        $unknown = $this->client()->request('DELETE', '/users/me/authorized-applications/'.Uuid::v7()->toRfc4122(), [
            'headers' => ['Authorization' => 'Bearer '.$this->ownerJwt],
        ]);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());

        // Compared without the stack trace, which only the debug environment adds and which
        // differs by the line the assertion was called from. What a caller can see is the
        // document, and it has to be the same document.
        self::assertSame($this->errorDocument($unknown), $this->errorDocument($foreign));
    }

    /** Revoking twice is a 404, not a 500: the second call finds nothing live to revoke. */
    #[Test]
    public function revokingTwiceIsNotFound(): void
    {
        $this->issueAccessTokenFor($this->owner, ['trips:read']);
        $id = $this->list()[0]['id'];
        self::assertIsString($id);

        $this->revoke($id);

        $second = $this->client()->request('DELETE', '/users/me/authorized-applications/'.$id, [
            'headers' => ['Authorization' => 'Bearer '.$this->ownerJwt],
        ]);

        self::assertSame(404, $second->getStatusCode());
    }

    /**
     * A grant whose access token expired but whose refresh token is alive still appears.
     *
     * An access token lives fifteen minutes. A list filtered on those alone would drop every
     * application a quarter of an hour after its last call, while it still holds a month of
     * refresh — telling a user they have nothing to revoke when they do.
     */
    #[Test]
    public function anApplicationHoldingOnlyARefreshTokenIsStillListed(): void
    {
        $this->issueAccessTokenFor($this->owner, ['trips:read']);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE oauth2_access_token SET expiry = now() - interval '1 hour'");

        self::assertCount(1, $this->list(), 'The refresh token is what still gives access.');

        $connection->executeStatement("UPDATE oauth2_refresh_token SET expiry = now() - interval '1 hour'");

        self::assertSame([], $this->list(), 'With nothing live left, the application is gone from the list.');
    }

    /**
     * Using the access is what "last used" means — and it is written once per window.
     *
     * The second half is not an optimisation detail: a handshake-era batch carries up to a
     * hundred messages, and a row written per message would turn a read into a hundred writes.
     * The throttle lives in a cache pool that stays on Redis under test for exactly this
     * assertion: on an array adapter every call would look like the first and this would pass
     * without proving anything.
     */
    #[Test]
    public function callingAToolRecordsTheUseOncePerWindow(): void
    {
        $token = $this->issueAccessTokenFor($this->owner, ['trips:read']);
        self::assertNull($this->grantRows()[0]['last_used_at']);

        // Connecting and reading the catalogue is not using the access. An agent that does only
        // that reads as never used, and the screen says so rather than implying activity.
        self::assertSame(200, $this->callMcp($token)->getStatusCode());
        self::assertNull($this->grantRows()[0]['last_used_at'], 'A tools/list is not a use.');

        self::assertSame(200, $this->callTool($token)->getStatusCode());
        $first = $this->grantRows()[0]['last_used_at'];
        self::assertIsString($first, 'Calling a tool is.');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE oauth_grant SET last_used_at = now() - interval '1 day'");

        $moved = $this->grantRows()[0]['last_used_at'];

        self::assertSame(200, $this->callTool($token)->getStatusCode());
        self::assertSame($moved, $this->grantRows()[0]['last_used_at'], 'A second call inside the window writes nothing.');
    }

    /**
     * Changing the address revokes the agents, instead of leaving them to fail in silence.
     *
     * The tokens are keyed on the email, and the user provider loads by it: after a change,
     * every token an agent holds resolves to nobody and answers 401 with nothing to explain it.
     * That was true before this unit and invisible; here it is said out loud. The grant rows
     * survive, because they key on the user — so the account page still lists what to let back
     * in.
     */
    #[Test]
    public function changingTheEmailRevokesTheAgentsAndKeepsTheGrants(): void
    {
        $token = $this->issueAccessTokenFor($this->owner, ['trips:read']);
        self::assertSame(200, $this->callMcp($token)->getStatusCode());

        $this->changeEmailTo('owner-renamed@example.com');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        // Asserted on the row, because the 401 proves nothing on its own: the token dies either
        // way, since the user provider loads by email and no longer finds anyone. The question
        // is whether it died by decision or by accident — and the difference shows the day the
        // address is changed back.
        $revoked = $connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token WHERE revoked = true');
        self::assertIsNumeric($revoked);
        self::assertSame(1, (int) $revoked, 'Changing the address must revoke the agents, not merely orphan them.');

        self::assertSame(401, $this->callMcp($token)->getStatusCode());

        $this->changeEmailTo('owner@example.com');
        self::assertSame(401, $this->callMcp($token)->getStatusCode(), 'Changing the address back must not resurrect an old token.');

        self::assertCount(1, $this->grantRows(), 'The grant belongs to the person, not to the address.');
    }

    private function changeEmailTo(string $newEmail): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $owner = $this->reloadOwner();
        $plain = 'change-'.bin2hex(random_bytes(8));

        // Seeded rather than driven through the request-a-link leg: what is under test is what
        // verifying does to the agents, not how the link is asked for (EmailChangeTest covers
        // that). The token is stored hashed at rest; the endpoint receives the plaintext.
        $em->persist(new EmailChangeToken($owner, hash('sha256', $plain), $newEmail, new \DateTimeImmutable('+30 minutes')));
        $em->flush();

        $response = $this->client()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$this->ownerJwt],
            'json' => ['token' => $plain],
        ]);

        self::assertSame(200, $response->getStatusCode());

        // The session token names the old address in its `username` claim, and the provider
        // loads by it — so it stops opening anything the moment the address changes. A browser
        // gets a new one from its refresh cookie; here, minting it is the shortest equivalent.
        $this->ownerJwt = self::createJwt($this->reloadOwner());
    }

    /**
     * @return array<string, mixed>
     */
    private function errorDocument(ResponseInterface $response): array
    {
        $document = $response->toArray(false);
        unset($document['trace']);

        return $document;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(): array
    {
        $response = $this->client()->request('GET', '/users/me/authorized-applications', [
            'headers' => ['Authorization' => 'Bearer '.$this->ownerJwt, 'Accept' => 'application/ld+json'],
        ]);

        self::assertSame(200, $response->getStatusCode());

        /** @var list<array<string, mixed>> $members */
        $members = $response->toArray(false)['member'] ?? [];

        return $members;
    }

    private function revoke(mixed $id): void
    {
        self::assertIsString($id);

        $response = $this->client()->request('DELETE', '/users/me/authorized-applications/'.$id, [
            'headers' => ['Authorization' => 'Bearer '.$this->ownerJwt],
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    private function callMcp(string $bearer): ResponseInterface
    {
        return $this->client()->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/list',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ],
        ]);
    }

    private function callTool(string $bearer): ResponseInterface
    {
        return $this->client()->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'list_trips',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_trips',
                    'arguments' => new \stdClass(),
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function client(): Client
    {
        return self::createClient();
    }

    private function reloadOwner(): User
    {
        $user = self::getContainer()->get('doctrine.orm.entity_manager')->find(User::class, $this->owner->getId());
        self::assertInstanceOf(User::class, $user);

        return $this->owner = $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grantRows(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative('SELECT * FROM oauth_grant ORDER BY authorized_at');

        return $rows;
    }
}
