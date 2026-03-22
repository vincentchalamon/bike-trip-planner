<?php

declare(strict_types=1);

namespace App\Test;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Decorates the real komoot.client to serve local HTML fixtures when MOCK_EXTERNAL_HTTP=true.
 *
 * Activated at runtime via environment variable (not at container compile time),
 * so it works with precompiled prod containers in CI.
 */
final readonly class MockKomootClientFactory implements HttpClientInterface
{
    private const string FIXTURES_DIR = __DIR__.'/../../tests/fixtures/komoot';

    public function __construct(
        private HttpClientInterface $inner,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ('true' !== ($_SERVER['MOCK_EXTERNAL_HTTP'] ?? $_ENV['MOCK_EXTERNAL_HTTP'] ?? null)) {
            return $this->inner->request($method, $url, $options);
        }

        $path = parse_url($url, \PHP_URL_PATH);

        if (!\is_string($path)) {
            return $this->createMockResponse('Not Found', 404);
        }

        if (preg_match('#/(?:[a-z]{2}-[a-z]{2}/)?tour/(\d+)#', $path, $matches)) {
            $fixtureFile = self::FIXTURES_DIR.\sprintf('/tour-%s.html', $matches[1]);
        } elseif (preg_match('#/(?:[a-z]{2}-[a-z]{2}/)?collection/(\d+)#', $path, $matches)) {
            $fixtureFile = self::FIXTURES_DIR.\sprintf('/collection-%s.html', $matches[1]);
        } else {
            return $this->createMockResponse('Not Found', 404);
        }

        if (!is_file($fixtureFile)) {
            throw new \RuntimeException(\sprintf('Komoot fixture not found: %s. Create the HTML fixture file to mock this tour in tests. See api/tests/fixtures/komoot/tour-2795080048.html for an example.', $fixtureFile));
        }

        $content = file_get_contents($fixtureFile);

        if (false === $content) {
            return $this->createMockResponse('Internal Server Error', 500);
        }

        return $this->createMockResponse($content, 200);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    private function createMockResponse(string $body, int $statusCode): ResponseInterface
    {
        $client = new MockHttpClient(new MockResponse($body, [
            'http_code' => $statusCode,
            'response_headers' => ['Content-Type' => 'text/html; charset=utf-8'],
        ]));

        return $client->request('GET', 'https://mock');
    }
}
