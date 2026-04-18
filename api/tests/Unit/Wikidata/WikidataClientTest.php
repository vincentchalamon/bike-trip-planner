<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wikidata;

use App\Wikidata\WikidataClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WikidataClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // query() — cache hit
    // -------------------------------------------------------------------------

    #[Test]
    public function queryCachedResultSkipsHttpCall(): void
    {
        $bindings = [
            ['item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q1']],
        ];

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn($bindings);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient);

        $result = $client->query('SELECT ?item WHERE { wd:Q1 ?p ?o }');

        $this->assertSame($bindings, $result);
    }

    // -------------------------------------------------------------------------
    // query() — cache miss → HTTP call
    // -------------------------------------------------------------------------

    #[Test]
    public function queryCacheMissFetchesAndCaches(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/wikidata/batch-response.json'),
            true,
        );
        \assert(\is_array($fixture));
        \assert(isset($fixture['results']) && \is_array($fixture['results']));
        \assert(isset($fixture['results']['bindings']) && \is_array($fixture['results']['bindings']));
        $bindings = $fixture['results']['bindings'];

        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())->method('expiresAfter')->with(604800);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn($fixture);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://query.wikidata.org/sparql', $this->arrayHasKey('query'))
            ->willReturn($response);

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient);

        $result = $client->query('SELECT ?item WHERE { VALUES ?item { wd:Q12345 wd:Q67890 } }');

        $this->assertSame($bindings, $result);
    }

    // -------------------------------------------------------------------------
    // query() — User-Agent is forwarded (HTTP client must be the scoped one)
    // -------------------------------------------------------------------------

    #[Test]
    public function queryPassesQueryParamsToHttpClient(): void
    {
        $sparql = 'SELECT ?item WHERE { wd:Q1 ?p ?o }';

        $item = $this->createStub(ItemInterface::class);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['results' => ['bindings' => []]]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://query.wikidata.org/sparql',
                $this->callback(static fn (array $options): bool => isset($options['query']['query'])
                    && $sparql === $options['query']['query']
                    && 'json' === $options['query']['format']),
            )
            ->willReturn($response);

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient);
        $client->query($sparql);
    }

    // -------------------------------------------------------------------------
    // query() — network error / timeout → silent empty result
    // -------------------------------------------------------------------------

    #[Test]
    public function queryLogsWarningAndReturnsEmptyOnHttpError(): void
    {
        $item = $this->createStub(ItemInterface::class);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('Connection timeout'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('failed'));

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient, logger: $logger);

        $result = $client->query('SELECT ?item WHERE { wd:Q1 ?p ?o }');

        $this->assertSame([], $result);
    }

    #[Test]
    public function queryReturnsEmptyArrayWhenBindingsMissing(): void
    {
        $item = $this->createStub(ItemInterface::class);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(static fn (string $key, callable $callback): mixed => $callback($item));

        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn(['results' => []]);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $client = $this->makeClient(cache: $cache, httpClient: $httpClient);

        $result = $client->query('SELECT ?item WHERE { wd:Q1 ?p ?o }');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(
        ?CacheInterface $cache = null,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ): WikidataClient {
        return new WikidataClient(
            httpClient: $httpClient ?? $this->createStub(HttpClientInterface::class),
            cache: $cache ?? $this->createStub(CacheInterface::class),
            logger: $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
