<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use App\Security\OAuth\McpCallBudget;
use App\Security\OAuth\McpToolScopes;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Who shares a bucket, and what spends from it — the two questions a limiter gets wrong
 * silently. The limits here are tiny so that each property is visible in a few calls; the real
 * sizes are pinned against the polling loop by
 * {@see \App\Tests\Integration\Mcp\McpCallBudgetSizingTest}.
 */
final class McpCallBudgetTest extends TestCase
{
    private RateLimiterFactory $calls;

    private RateLimiterFactory $mutations;

    private TokenStorage $tokens;

    #[\Override]
    protected function setUp(): void
    {
        $storage = new InMemoryStorage();
        $this->calls = new RateLimiterFactory(['id' => 'calls', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '60 seconds'], $storage);
        $this->mutations = new RateLimiterFactory(['id' => 'mutations', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], $storage);
        $this->tokens = new TokenStorage();
    }

    /**
     * One call, one token: a handshake-era batch reaches the handler once per message, so a
     * batch of four spends four and the fourth is refused.
     */
    #[Test]
    public function everyCallSpendsWhateverEnvelopeItCameIn(): void
    {
        $this->actAs('alice@example.com', 'https://agent.example.com/client.json');
        $budget = $this->budget();

        $answers = array_map(fn (int $id): Response|Error => $budget->handle($this->call('get_trip', $id), $this->session()), [1, 2, 3, 4]);

        self::assertContainsOnlyInstancesOf(Response::class, \array_slice($answers, 0, 3));
        self::assertInstanceOf(Error::class, $answers[3]);
        self::assertSame(4, $answers[3]->id, 'The refusal answers the call it refuses.');
    }

    #[Test]
    public function aRefusalSaysWhenToComeBack(): void
    {
        $this->actAs('alice@example.com', 'https://agent.example.com/client.json');
        $budget = $this->budget();

        foreach ([1, 2, 3] as $id) {
            $budget->handle($this->call('get_trip', $id), $this->session());
        }

        $refusal = $budget->handle($this->call('get_trip', 4), $this->session());

        self::assertInstanceOf(Error::class, $refusal);
        self::assertIsArray($refusal->data);
        self::assertSame('rate_limited', $refusal->data['error']);
        self::assertIsInt($refusal->data['retryAfter']);
        self::assertGreaterThanOrEqual(1, $refusal->data['retryAfter']);
    }

    /** Two agents of one person get two buckets; one agent serving two people does too. */
    #[Test]
    public function aBucketIsOnePersonAndOneAgent(): void
    {
        $budget = $this->budget();

        foreach (['alice@example.com|https://a.example/c.json', 'alice@example.com|https://b.example/c.json', 'bob@example.com|https://a.example/c.json'] as $caller) {
            [$user, $client] = explode('|', $caller);
            $this->actAs($user, $client);

            foreach ([1, 2, 3] as $id) {
                self::assertInstanceOf(Response::class, $budget->handle($this->call('get_trip', $id), $this->session()), $caller." was refused on someone else's spending.");
            }
        }
    }

    /** A write spends from both budgets; a read only from the first. */
    #[Test]
    public function aWriteAlsoSpendsFromTheMutationBudget(): void
    {
        $this->actAs('alice@example.com', 'https://agent.example.com/client.json');
        $budget = $this->budget();

        self::assertInstanceOf(Response::class, $budget->handle($this->call('delete_trip', 1), $this->session()));
        self::assertInstanceOf(Error::class, $budget->handle($this->call('delete_trip', 2), $this->session()), 'The second write exceeds the mutation budget of one.');
        self::assertInstanceOf(Response::class, $budget->handle($this->call('get_trip', 3), $this->session()), 'Reads still have room in the call budget.');
    }

    /** A message that names no tool — a listing, a ping — is not a call and spends nothing. */
    #[Test]
    public function aListingSpendsNothing(): void
    {
        $this->actAs('alice@example.com', 'https://agent.example.com/client.json');
        $budget = $this->budget();

        foreach (range(1, 10) as $id) {
            self::assertInstanceOf(Response::class, $budget->handle(new ListToolsRequest()->withId($id), $this->session()));
        }
    }

    private function actAs(string $user, string $client): void
    {
        $this->tokens->setToken(new OAuth2Token(new InMemoryUser($user, null), 'token-id', $client, ['trips:read', 'trips:write'], 'ROLE_OAUTH2_'));
    }

    private function call(string $tool, int $id): CallToolRequest
    {
        return new CallToolRequest($tool, [])->withId($id);
    }

    private function session(): SessionInterface
    {
        return $this->createStub(SessionInterface::class);
    }

    private function budget(): McpCallBudget
    {
        $names = $this->createStub(ResourceNameCollectionFactoryInterface::class);
        $names->method('create')->willReturn(new ResourceNameCollection([\stdClass::class]));

        $metadata = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $metadata->method('create')->willReturn(new ResourceMetadataCollection(\stdClass::class, [
            new ApiResource(mcp: [
                'get_trip' => new McpTool(name: 'get_trip', extraProperties: ['mcp_scope' => 'trips:read']),
                'delete_trip' => new McpTool(name: 'delete_trip', extraProperties: ['mcp_scope' => 'trips:write']),
            ]),
        ]));

        $inner = new class () implements RequestHandlerInterface {
            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response
            {
                return new Response($request->getId(), new CallToolResult([]));
            }
        };

        return new McpCallBudget($inner, new McpToolScopes($names, $metadata), $this->tokens, $this->calls, $this->mutations);
    }
}
