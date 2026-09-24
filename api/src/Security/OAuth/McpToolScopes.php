<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;

/**
 * Which scope each MCP tool consumes, read from the tools themselves (ADR-079).
 *
 * The scope a tool needs is declared once, on the tool, in `extraProperties['mcp_scope']`.
 * This reads it back — nobody maintains a second list, which is the failure mode this
 * project keeps running into: a table beside the thing it describes, drifting quietly.
 *
 * {@see \App\Tests\Unit\Security\OAuth\McpToolScopeCoverageTest} asserts every declared tool
 * has one. A tool without a scope would be open to any token.
 */
final class McpToolScopes
{
    /** @var array<string, string>|null */
    private ?array $scopes = null;

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
    ) {
    }

    public function requiredBy(string $toolName): ?string
    {
        return $this->all()[$toolName] ?? null;
    }

    /**
     * @return array<string, string> tool name => scope
     */
    public function all(): array
    {
        if (null !== $this->scopes) {
            return $this->scopes;
        }

        $scopes = [];

        foreach ($this->resourceNames->create() as $resourceClass) {
            foreach ($this->resourceMetadata->create($resourceClass) as $resource) {
                foreach ($resource->getMcp() ?? [] as $key => $operation) {
                    $scope = $operation->getExtraProperties()['mcp_scope'] ?? null;
                    if (!\is_string($scope)) {
                        continue;
                    }

                    // Both spellings on purpose. The declaration key is the name a client
                    // addresses the tool by; `getName()` is API Platform's own, which it is
                    // free to rewrite into a generated operation name. Keying on only one of
                    // them fails silently — the lookup misses and the refusal falls through
                    // to the ownership path, reporting a missing trip for a missing scope.
                    foreach ($this->namesOf($key, $operation) as $name) {
                        $scopes[$name] = $scope;
                    }
                }
            }
        }

        return $this->scopes = $scopes;
    }

    /**
     * @return list<string> names of tools that declare no scope
     */
    public function withoutScope(): array
    {
        $declared = [];

        foreach ($this->resourceNames->create() as $resourceClass) {
            foreach ($this->resourceMetadata->create($resourceClass) as $resource) {
                foreach ($resource->getMcp() ?? [] as $key => $operation) {
                    $declared[] = $this->namesOf($key, $operation)[0];
                }
            }
        }

        return array_values(array_diff($declared, array_keys($this->all())));
    }

    /**
     * @return non-empty-list<string>
     */
    private function namesOf(mixed $key, object $operation): array
    {
        $names = [];

        if (\is_string($key) && '' !== $key) {
            $names[] = $key;
        }

        if (method_exists($operation, 'getName') && \is_string($name = $operation->getName()) && '' !== $name) {
            $names[] = $name;
        }

        return [] === $names ? ['(unnamed)'] : array_values(array_unique($names));
    }

    /**
     * `ROLE_OAUTH2_` + the scope, uppercased — how the bundle turns a scope into a role
     * ({@see \League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token}).
     * The colon survives, which is ugly and load-bearing.
     */
    public static function role(string $scope): string
    {
        return strtoupper('ROLE_OAUTH2_'.$scope);
    }
}
