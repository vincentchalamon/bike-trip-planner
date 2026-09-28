<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GeocodeResult;
use App\Geo\NominatimPlaces;
use App\State\Mcp\McpArguments;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Place search as an API Platform operation, so it appears in the OpenAPI document and the
 * generated types, and so an `McpTool` has something to attach to. The lookup itself, cache
 * and throttle included, is {@see NominatimPlaces}'s.
 *
 * @implements ProviderInterface<GeocodeResult>
 */
final readonly class GeocodeSearchProvider implements ProviderInterface
{
    public function __construct(
        private NominatimPlaces $places,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<GeocodeResult>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        // Query parameters on HTTP, tool arguments on MCP — where neither the query string nor
        // `$context['filters']` is populated. One reader for both.
        $filters = McpArguments::filters($context);

        $query = trim(\is_string($filters['q'] ?? null) ? $filters['q'] : '');

        if ('' === $query) {
            throw new BadRequestHttpException('Missing required parameter: q');
        }

        $limit = min(max(\is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 5, 1), 10);

        return $this->places->search($query, $limit);
    }
}
