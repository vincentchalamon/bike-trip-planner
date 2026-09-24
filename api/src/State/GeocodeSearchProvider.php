<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GeocodeResult;
use App\Service\NominatimThrottle;
use App\State\Mcp\McpArguments;
use App\State\Mcp\ThirdPartyText;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Place search, moved out of {@see \App\Controller\GeocodeController} and into the contract.
 *
 * The body is the controller's, unchanged in what it does: the 24-hour cache, the per-user
 * outbound throttle, the 1..10 clamp and the field mapping all behave as they did. What
 * changes is that it is now an API Platform operation, so it appears in the OpenAPI document
 * and the generated types, and so an `McpTool` has something to attach to.
 *
 * @implements ProviderInterface<GeocodeResult>
 */
final readonly class GeocodeSearchProvider implements ProviderInterface
{
    private const int CACHE_TTL = 86400;

    public function __construct(
        #[Autowire(service: 'nominatim.client')]
        private HttpClientInterface $nominatim,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $cache,
        private NominatimThrottle $throttle,
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

        $item = $this->cache->getItem(\sprintf('geocode.search.%s.%d', md5($query), $limit));

        if ($item->isHit()) {
            /** @var list<GeocodeResult> $cached */
            $cached = $item->get();

            return $cached;
        }

        // After the cache on purpose: a repeated search costs the third party nothing, so it
        // should not cost the caller a token either.
        $this->throttle->throttle();

        $results = $this->fetch($query, $limit);

        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);

        $this->cache->save($item);

        return $results;
    }

    /**
     * @return list<GeocodeResult>
     */
    private function fetch(string $query, int $limit): array
    {
        try {
            /** @var list<array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string}> $data */
            $data = $this->nominatim->request('GET', '/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => $limit,
                    'addressdetails' => 1,
                ],
            ])->toArray();
        } catch (\Throwable) {
            // 502, as the controller answered: the failure is upstream, not in the request.
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'Geocoding service unavailable');
        }

        // Cleaned here, at the boundary where OpenStreetMap's text enters this application,
        // rather than at a projection further down. `search_places` is the one tool marked
        // `openWorldHint`, so this is the one place the answer is built out of a third party's
        // strings and the only sensible home for the hygiene every other third-party label in
        // the unit gets. `name` and `displayName` are both labels — a full address runs long
        // but stays well inside the cap, which is what the cap is for.
        return array_map(
            static fn (array $place): GeocodeResult => new GeocodeResult(
                name: ThirdPartyText::clean($place['name'] ?? null) ?? '',
                lat: (float) ($place['lat'] ?? 0),
                lon: (float) ($place['lon'] ?? 0),
                displayName: ThirdPartyText::clean($place['display_name'] ?? null) ?? '',
                type: $place['addresstype'] ?? $place['type'] ?? 'place',
            ),
            $data,
        );
    }
}
