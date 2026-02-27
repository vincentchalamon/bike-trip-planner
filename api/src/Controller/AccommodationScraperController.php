<?php

declare(strict_types=1);

namespace App\Controller;

use App\Accommodation\AccommodationMetadataExtractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AccommodationScraperController
{
    public function __construct(
        #[Autowire(service: 'accommodation_scraper.client')]
        private HttpClientInterface $httpClient,
        private AccommodationMetadataExtractor $extractor,
    ) {
    }

    #[Route('/accommodations/scrape', methods: ['POST'])]
    public function scrape(Request $request): JsonResponse
    {
        /** @var array{url?: string} $payload */
        $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $url = $payload['url'] ?? '';

        if ('' === $url || !filter_var($url, \FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            return new JsonResponse(['error' => 'Invalid URL'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $response = $this->httpClient->request('GET', $url);
            $html = $response->getContent();
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Failed to fetch URL'], Response::HTTP_GATEWAY_TIMEOUT);
        }

        $scraped = $this->extractor->extract($html);

        return new JsonResponse([
            'name' => $scraped->name,
            'type' => $scraped->type,
            'priceMin' => $scraped->priceMin,
            'priceMax' => $scraped->priceMax,
        ]);
    }
}
