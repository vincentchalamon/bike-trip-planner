<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use App\Security\OAuth\McpScopeGuard;
use App\Security\OAuth\McpToolScopes;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Every kind of message the decorated handler serves is judged, and what is not judged is let
 * through untouched. The transport-level ways past the old header check are exercised end to
 * end by {@see \App\Tests\Functional\McpScopeEnforcementTest}; this pins the decision itself.
 */
final class McpScopeGuardTest extends TestCase
{
    #[Test]
    public function aToolCallWithTheScopeReachesTheHandler(): void
    {
        $inner = $this->inner();

        $answer = $this->guard($inner, granted: true)->handle($this->call('delete_trip'), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $answer);
        self::assertSame(1, $inner->calls);
    }

    #[Test]
    public function aToolCallWithoutTheScopeNeverReachesTheHandler(): void
    {
        $inner = $this->inner();

        $answer = $this->guard($inner, granted: false)->handle($this->call('delete_trip'), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame(7, $answer->id, 'The refusal answers the message it refuses.');
        self::assertSame(['error' => 'insufficient_scope', 'scope' => 'trips:write'], $answer->data);
        self::assertSame(0, $inner->calls);
    }

    /**
     * Fail closed: an element the handler serves but no scope is declared for is refused, even to
     * a caller holding every role.
     */
    #[Test]
    public function aToolWithNoDeclaredScopeIsRefused(): void
    {
        $inner = $this->inner();

        $answer = $this->guard($inner, granted: true)->handle($this->call('undeclared_tool'), $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame(0, $inner->calls);
    }

    /**
     * The same handler serves `resources/read`. None is declared today; one that appears without
     * a scope the guard can find must not be served to any token.
     */
    #[Test]
    public function aResourceReadIsJudgedToo(): void
    {
        $inner = $this->inner();

        $answer = $this->guard($inner, granted: true)->handle(
            new ReadResourceRequest('btp://trips/export')->withId(3),
            $this->createStub(SessionInterface::class),
        );

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame(0, $inner->calls);
    }

    /** Anything that names no element is not this guard's to judge. */
    #[Test]
    public function aMessageThatNamesNothingPassesThrough(): void
    {
        $inner = $this->inner();

        $this->guard($inner, granted: false)->handle(new ListToolsRequest()->withId(1), $this->createStub(SessionInterface::class));

        self::assertSame(1, $inner->calls);
    }

    private function call(string $tool): CallToolRequest
    {
        return new CallToolRequest($tool, [])->withId(7);
    }

    /**
     * @param RequestHandlerInterface<CallToolResult> $inner
     */
    private function guard(RequestHandlerInterface $inner, bool $granted): McpScopeGuard
    {
        $names = $this->createStub(ResourceNameCollectionFactoryInterface::class);
        $names->method('create')->willReturn(new ResourceNameCollection([\stdClass::class]));

        $metadata = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $metadata->method('create')->willReturn(new ResourceMetadataCollection(\stdClass::class, [
            new ApiResource(mcp: [
                'delete_trip' => new McpTool(name: 'delete_trip', extraProperties: ['mcp_scope' => 'trips:write']),
            ]),
        ]));

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return new McpScopeGuard($inner, new McpToolScopes($names, $metadata), $checker);
    }

    /**
     * @return RequestHandlerInterface<CallToolResult>&object{calls: int}
     */
    private function inner(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public int $calls = 0;

            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response
            {
                ++$this->calls;

                return new Response($request->getId(), new CallToolResult([]));
            }
        };
    }
}
