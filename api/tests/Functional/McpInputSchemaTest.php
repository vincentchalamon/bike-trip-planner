<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What `tools/list` tells a model it may pass, read the way a client reads it.
 *
 * The four read tools declared no input class, so each published the fields of its RESOURCE as
 * arguments — `get_stage` offered `geometry` and `alerts` to fill in and never mentioned
 * `stageId`; `search_places` never mentioned `q`, which its own description asked for — and no
 * tool marked any argument required. Found by running the MCP Inspector against the server;
 * {@see \App\Tests\Integration\Mcp\McpToolContractTest::everyToolPublishesTheArgumentsItIsAddressedByAsRequired}
 * guards the addressing arguments of every tool, this pins the whole contract of the reads.
 */
#[ResetDatabase]
final class McpInputSchemaTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private Client $client;

    private User $owner;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $tools = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function reads(): iterable
    {
        yield 'get_trip' => ['get_trip', ['id'], ['id']];
        yield 'get_stage' => ['get_stage', ['tripId', 'stageId'], ['tripId', 'stageId']];
        yield 'list_trips' => ['list_trips', ['page', 'itemsPerPage', 'title', 'startDate', 'endDate'], []];
        yield 'search_places' => ['search_places', ['q', 'limit'], ['q']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $required
     */
    #[Test]
    #[DataProvider('reads')]
    public function aReadToolPublishesExactlyTheArgumentsItReads(string $tool, array $arguments, array $required): void
    {
        $schema = $this->tools()[$tool]['inputSchema'] ?? null;
        self::assertIsArray($schema, $tool.' publishes no input schema.');

        $properties = $schema['properties'] ?? [];
        self::assertIsArray($properties);
        self::assertEqualsCanonicalizing($arguments, array_keys($properties));
        self::assertEqualsCanonicalizing($required, $schema['required'] ?? []);
    }

    /**
     * A `type` written as an array (`["string","null"]`) is legal JSON Schema but not portable:
     * clients that map tool schemas onto a single-type dialect reject the tool or drop the
     * constraint. The Inspector flagged four, all in `get_stage`; three were in the fields it
     * wrongly published as arguments, the fourth in the inferred schema of its alerts.
     */
    #[Test]
    public function noToolSchemaWritesATypeAsAnArray(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $tool) {
            foreach (['inputSchema', 'outputSchema'] as $which) {
                if (\is_array($tool[$which] ?? null)) {
                    foreach ($this->typeArrays($tool[$which], $which) as $path) {
                        $offenders[] = $name.': '.$path;
                    }
                }
            }
        }

        self::assertSame([], $offenders, "Schema path(s) with an array `type`:\n  ".implode("\n  ", $offenders));
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private function typeArrays(array $node, string $path): array
    {
        $found = \is_array($node['type'] ?? null) ? [$path] : [];

        foreach ($node as $key => $child) {
            if (!\is_array($child)) {
                continue;
            }

            // Under `properties`, keys are property NAMES — a property called `type` is a schema,
            // not the `type` keyword — so each child is walked as a schema of its own.
            if ('properties' === $key) {
                foreach ($child as $property => $schema) {
                    if (\is_array($schema)) {
                        $found = [...$found, ...$this->typeArrays($schema, $path.'.properties.'.$property)];
                    }
                }

                continue;
            }

            $found = [...$found, ...$this->typeArrays($child, $path.'.'.$key)];
        }

        return $found;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tools(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $token = $this->issueAccessTokenFor($this->owner, ['trips:read', 'trips:write']);
        $envelope = $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/list',
                'Authorization' => 'Bearer '.$token,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ],
        ])->toArray(false);

        $tools = [];
        foreach ($envelope['result']['tools'] ?? [] as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name'] ?? null);
            $tools[$tool['name']] = $tool;
        }

        self::assertNotSame([], $tools, 'tools/list returned nothing to check.');

        return $this->tools = $tools;
    }
}
