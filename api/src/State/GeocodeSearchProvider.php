<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GeocodeResult;
use App\State\Mcp\McpArguments;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private Security $security,
        #[Autowire(service: 'limiter.geocode')]
        private RateLimiterFactory $limiter,
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
        $this->throttleOutbound();

        $results = $this->fetch($query, $limit);

        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);

        $this->cache->save($item);

        return $results;
    }

    /**
     * Throttles outbound calls to the public Nominatim instance per user (2026-07 security
     * audit): its usage policy caps bulk use and bans IPs, and the whole deployment shares one.
     *
     * Per user, which bounds the aggregate only in proportion to how many users there are. An
     * agent loop is machine-paced where a person typing is not, so this is the limiter that
     * matters most here and the one an MCP call goes through — the tool reuses this provider
     * rather than getting a second, parallel limit that would protect nothing extra.
     */
    private function throttleOutbound(): void
    {
        $key = $this->security->getUser()?->getUserIdentifier() ?? 'anonymous';

        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
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

        return array_map(
            static fn (array $place): GeocodeResult => new GeocodeResult(
                name: $place['name'] ?? '',
                lat: (float) ($place['lat'] ?? 0),
                lon: (float) ($place['lon'] ?? 0),
                displayName: $place['display_name'] ?? '',
                type: $place['addresstype'] ?? $place['type'] ?? 'place',
            ),
            $data,
        );
    }
}
