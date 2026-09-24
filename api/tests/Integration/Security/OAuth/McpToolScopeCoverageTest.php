<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\OAuth;

use App\Security\OAuth\McpResource;
use App\Security\OAuth\McpToolScopes;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every MCP tool declares the scope it consumes.
 *
 * A tool without one is reachable by any token that gets through the firewall, whatever the
 * user consented to — and nothing else would notice, because the refusal machinery has
 * nothing to compare against. The map is read from the tools themselves, so this is the
 * guard that keeps the declaration from being forgotten rather than from drifting.
 */
final class McpToolScopeCoverageTest extends KernelTestCase
{
    #[Test]
    public function everyToolNamesTheScopeItNeeds(): void
    {
        self::bootKernel();

        /** @var McpToolScopes $scopes */
        $scopes = self::getContainer()->get(McpToolScopes::class);

        self::assertSame(
            [],
            $scopes->withoutScope(),
            'An MCP tool declares no mcp_scope: it would be callable with any token.',
        );
        self::assertNotSame([], $scopes->all(), 'No MCP tool was found at all — the scan is looking in the wrong place.');
    }

    #[Test]
    public function everyDeclaredScopeIsOneTheServerIssues(): void
    {
        self::bootKernel();

        /** @var McpToolScopes $scopes */
        $scopes = self::getContainer()->get(McpToolScopes::class);

        foreach ($scopes->all() as $tool => $scope) {
            self::assertContains(
                $scope,
                McpResource::SCOPES,
                \sprintf('Tool "%s" asks for a scope no token can carry, so it can never be called.', $tool),
            );
        }
    }
}
