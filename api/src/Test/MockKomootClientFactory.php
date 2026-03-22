<?php

declare(strict_types=1);

namespace App\Test;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Replaces the real komoot.client with a MockHttpClient in test environment.
 *
 * Returns fixture HTML files from api/tests/fixtures/komoot/ based on the URL path.
 * Falls through with a 404 if no fixture exists for the requested path.
 *
 * @internal used only in test/CI to make the integration smoke test deterministic
 */
final readonly class MockKomootClientFactory
{
    private const string FIXTURES_DIR = __DIR__.'/../../tests/fixtures/komoot';

    public static function create(): HttpClientInterface
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            $path = parse_url($url, \PHP_URL_PATH);

            if (!\is_string($path)) {
                return new MockResponse('Not Found', ['http_code' => 404]);
            }

            // Map URL path to fixture file: /tour/2795080048 → tour-2795080048.html
            // Also handles /fr-fr/tour/2795080048 → tour-2795080048.html
            if (preg_match('#/(?:[a-z]{2}-[a-z]{2}/)?tour/(\d+)#', $path, $matches)) {
                $fixtureFile = self::FIXTURES_DIR.\sprintf('/tour-%s.html', $matches[1]);
            } elseif (preg_match('#/(?:[a-z]{2}-[a-z]{2}/)?collection/(\d+)#', $path, $matches)) {
                $fixtureFile = self::FIXTURES_DIR.\sprintf('/collection-%s.html', $matches[1]);
            } else {
                return new MockResponse('Not Found', ['http_code' => 404]);
            }

            if (!is_file($fixtureFile)) {
                throw new \RuntimeException(\sprintf('Komoot fixture not found: %s. Create the HTML fixture file to mock this tour in tests. See api/tests/fixtures/komoot/tour-2795080048.html for an example.', $fixtureFile));
            }

            $content = file_get_contents($fixtureFile);

            if (false === $content) {
                return new MockResponse('Internal Server Error', ['http_code' => 500]);
            }

            return new MockResponse($content, [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'text/html; charset=utf-8'],
            ]);
        }, 'https://www.komoot.com');
    }
}
