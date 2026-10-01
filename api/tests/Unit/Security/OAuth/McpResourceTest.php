<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Security\OAuth\McpResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpResource::class)]
final class McpResourceTest extends TestCase
{
    #[Test]
    public function theMetadataUrlIsPathInsertedUnderTheIssuer(): void
    {
        $resource = new McpResource('https://example.test/');

        self::assertSame('https://example.test/mcp', $resource->canonicalUri());
        self::assertSame('https://example.test/.well-known/oauth-protected-resource/mcp', $resource->metadataUrl());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'the endpoint' => ['/mcp', true];
        yield 'below the endpoint' => ['/mcp/session', true];
        yield 'a sibling sharing the prefix' => ['/mcpx', false];
        yield 'a hyphenated sibling' => ['/mcp-tokens', false];
        yield 'elsewhere' => ['/trips', false];
    }

    #[Test]
    #[DataProvider('paths')]
    public function servesOnlyTheMcpPathAndBelow(string $path, bool $expected): void
    {
        self::assertSame($expected, McpResource::serves($path));
    }
}
