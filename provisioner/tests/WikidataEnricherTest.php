<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\WikidataEnricher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WikidataEnricherTest extends TestCase
{
    /**
     * @param list<array<string, array{value: string}>> $bindings
     */
    private function enricher(array $bindings, ?\Closure $onRequest = null): WikidataEnricher
    {
        $response = new MockResponse((string) json_encode(['results' => ['bindings' => $bindings]]));
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, $onRequest): MockResponse {
            if ($onRequest instanceof \Closure) {
                $onRequest($method, $url, $options);
            }

            return $response;
        });

        return new WikidataEnricher($client);
    }

    #[Test]
    public function parsesAllFieldsFromASparqlBinding(): void
    {
        $enricher = $this->enricher([
            [
                'item' => ['value' => 'http://www.wikidata.org/entity/Q243'],
                'itemLabel' => ['value' => 'Tour Eiffel'],
                'itemDescription' => ['value' => 'tour de fer a Paris'],
                'image' => ['value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/Tour%20Eiffel.jpg'],
                'website' => ['value' => 'https://www.toureiffel.paris'],
                'openingHours' => ['value' => '09:00-23:00'],
                'article' => ['value' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel'],
            ],
        ]);

        $result = $enricher->enrich(['Q243'], 'fr');

        self::assertSame([
            'Q243' => [
                'label' => 'Tour Eiffel',
                'description' => 'tour de fer a Paris',
                'website' => 'https://www.toureiffel.paris',
                'openingHours' => '09:00-23:00',
                'wikipediaUrl' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel',
                'imageUrl' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Tour%20Eiffel.jpg?width=400',
            ],
        ], $result);
    }

    #[Test]
    public function skipsInvalidQIdsAndSendsOnlySafeValues(): void
    {
        $captured = null;
        $enricher = $this->enricher([], function (string $method, string $url) use (&$captured): void {
            $captured = $url;
        });

        $result = $enricher->enrich(['Q1', 'not-a-qid', 'Q2; DROP TABLE'], 'en');

        self::assertSame([], $result);
        self::assertIsString($captured);
        self::assertStringContainsString('wd:Q1', $captured);
        self::assertStringNotContainsString('DROP', $captured);
    }

    #[Test]
    public function returnsEmptyWhenNoQIds(): void
    {
        $enricher = new WikidataEnricher(new MockHttpClient(new MockResponse('boom', ['http_code' => 500])));

        self::assertSame([], $enricher->enrich([], 'fr'));
    }

    #[Test]
    public function swallowsHttpFailuresAndYieldsNoEnrichment(): void
    {
        $enricher = new WikidataEnricher(new MockHttpClient(new MockResponse('boom', ['http_code' => 500])));

        self::assertSame([], $enricher->enrich(['Q243'], 'fr'));
    }
}
