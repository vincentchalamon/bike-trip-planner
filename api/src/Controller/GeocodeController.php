<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GeocodeController
{
    private const int CACHE_TTL = 86400; // 24 hours

    public function __construct(
        #[Autowire(service: 'nominatim.client')]
        private HttpClientInterface $nominatimClient,
        #[Autowire(service: 'cache.osm')]
        private CacheItemPoolInterface $osmCache,
    ) {
    }

    #[Route('/geocode/search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->getString('q');

        if ('' === $query) {
            return new JsonResponse(['error' => 'Missing required parameter: q'], Response::HTTP_BAD_REQUEST);
        }

        $limit = $request->query->getInt('limit', 5);
        $limit = min(max($limit, 1), 10);

        $cacheKey = \sprintf('geocode.search.%s.%d', md5($query), $limit);
        $item = $this->osmCache->getItem($cacheKey);

        if ($item->isHit()) {
            /** @var list<array{name: string, lat: float, lon: float, displayName: string, type: string}> $cached */
            $cached = $item->get();

            return new JsonResponse(['results' => $cached]);
        }

        try {
            $response = $this->nominatimClient->request('GET', '/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => $limit,
                    'addressdetails' => 1,
                ],
            ]);

            /** @var list<array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string}> $data */
            $data = $response->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Geocoding service unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        $results = array_map(
            static fn (array $place): array => [
                'name' => $place['name'] ?? '',
                'lat' => (float) ($place['lat'] ?? 0),
                'lon' => (float) ($place['lon'] ?? 0),
                'displayName' => $place['display_name'] ?? '',
                'type' => $place['addresstype'] ?? $place['type'] ?? 'place',
            ],
            $data,
        );

        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);

        $this->osmCache->save($item);

        return new JsonResponse(['results' => $results]);
    }

    #[Route('/geocode/reverse', methods: ['GET'])]
    public function reverse(Request $request): JsonResponse
    {
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        if (null === $lat || '' === $lat || null === $lon || '' === $lon) {
            return new JsonResponse(['error' => 'Missing required parameters: lat, lon'], Response::HTTP_BAD_REQUEST);
        }

        $latFloat = (float) $lat;
        $lonFloat = (float) $lon;

        $cacheKey = \sprintf('geocode.reverse.%s.%s', round($latFloat, 4), round($lonFloat, 4));
        $item = $this->osmCache->getItem($cacheKey);

        if ($item->isHit()) {
            /** @var list<array{name: string, lat: float, lon: float, displayName: string, type: string}> $cached */
            $cached = $item->get();

            return new JsonResponse(['results' => $cached]);
        }

        try {
            $response = $this->nominatimClient->request('GET', '/reverse', [
                'query' => [
                    'lat' => $latFloat,
                    'lon' => $lonFloat,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                ],
            ]);

            /** @var array{name?: string, display_name?: string, lat?: string, lon?: string, type?: string, addresstype?: string, error?: string} $data */
            $data = $response->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Geocoding service unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        if (isset($data['error'])) {
            return new JsonResponse(['results' => []]);
        }

        $results = [[
            'name' => $data['name'] ?? '',
            'lat' => (float) ($data['lat'] ?? $latFloat),
            'lon' => (float) ($data['lon'] ?? $lonFloat),
            'displayName' => $data['display_name'] ?? '',
            'type' => $data['addresstype'] ?? $data['type'] ?? 'place',
        ]];

        $item->set($results);
        $item->expiresAfter(self::CACHE_TTL);

        $this->osmCache->save($item);

        return new JsonResponse(['results' => $results]);
    }
}
