<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use App\RouteFetcher\KomootCollectionRouteFetcher;
use App\RouteFetcher\KomootHtmlExtractor;
use App\RouteFetcher\RouteResponseSize;
use App\RouteParser\RouteTooLargeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KomootCollectionRouteFetcherTest extends TestCase
{
    private const string URL = 'https://www.komoot.com/collection/42';

    #[Test]
    public function itFetchesEveryTourOfACollection(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return str_contains($url, '/collection/')
                ? new MockResponse($this->collectionHtml(10))
                : new MockResponse($this->tourHtml());
        }, 'https://www.komoot.com');

        $result = $this->fetcher($client)->fetch(self::URL);

        self::assertCount(10, $result->tracks);
        self::assertCount(11, $requested);
    }

    #[Test]
    public function aCollectionPastTheTourCapIsRefusedBeforeAnyTourIsFetched(): void
    {
        $requested = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            ++$requested;

            return new MockResponse($this->collectionHtml(KomootCollectionRouteFetcher::MAX_TOURS + 1));
        }, 'https://www.komoot.com');

        try {
            $this->fetcher($client)->fetch(self::URL);
            self::fail('A collection past the cap must be refused.');
        } catch (RouteTooLargeException) {
            self::assertSame(1, $requested, 'Only the collection page may be requested.');
        }
    }

    #[Test]
    public function anOversizedTourPageFailsTheCollection(): void
    {
        $client = new MockHttpClient(fn (string $method, string $url): MockResponse => str_contains($url, '/collection/')
            ? new MockResponse($this->collectionHtml(2))
            : new MockResponse($this->tourHtml(), ['response_headers' => ['content-length' => (string) (RouteResponseSize::MAX_BYTES + 1)]]), 'https://www.komoot.com');

        try {
            $this->fetcher($client)->fetch(self::URL);
            self::fail('An oversized tour page must fail the collection.');
        } catch (\RuntimeException $runtimeException) {
            // The transport wraps the error raised by the progress guard.
            self::assertInstanceOf(RouteTooLargeException::class, $runtimeException->getPrevious());
        }
    }

    private function fetcher(MockHttpClient $client): KomootCollectionRouteFetcher
    {
        return new KomootCollectionRouteFetcher($client, new KomootHtmlExtractor(), new ArrayAdapter());
    }

    private function collectionHtml(int $tours): string
    {
        return $this->bootstrapHtml(['page' => ['_embedded' => ['collectionHal' => [
            'name' => 'Collection',
            '_embedded' => ['compilation' => ['_embedded' => ['items' => array_map(
                static fn (int $id): array => ['id' => $id],
                range(1, $tours),
            )]]],
        ]]]]);
    }

    private function tourHtml(): string
    {
        return $this->bootstrapHtml(['page' => ['_embedded' => ['tour' => [
            'name' => 'Tour',
            '_embedded' => ['coordinates' => ['items' => [
                ['lat' => 44.0, 'lng' => 5.0, 'alt' => 100.0],
                ['lat' => 44.1, 'lng' => 5.1, 'alt' => 110.0],
            ]]],
        ]]]]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bootstrapHtml(array $data): string
    {
        return '<html><body><script>kmtBoot.setProps("'.addcslashes(json_encode($data, \JSON_THROW_ON_ERROR), '"\\').'")</script></body></html>';
    }
}
