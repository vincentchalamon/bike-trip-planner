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
     * @param list<MockResponse>                                          $responses
     * @param (\Closure(string, string, array<string, mixed>): void)|null $onRequest
     */
    private function enricher(array $responses, ?\Closure $onRequest = null): WikidataEnricher
    {
        $i = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$i, $responses, $onRequest): MockResponse {
            if ($onRequest instanceof \Closure) {
                $onRequest($method, $url, $options);
            }

            return $responses[$i++] ?? new MockResponse((string) json_encode(['results' => ['bindings' => []]]));
        });

        // No-op sleeper so backoff never waits during tests.
        return new WikidataEnricher($client, sleep: static fn (int $seconds): null => null);
    }

    /**
     * @param list<array<string, array{value: string}>> $bindings
     */
    private function bindingsResponse(array $bindings): MockResponse
    {
        return new MockResponse((string) json_encode(['results' => ['bindings' => $bindings]]));
    }

    /**
     * @param list<string> $qIds
     *
     * @return array<string, array<string, string>|null>
     */
    private function collect(WikidataEnricher $enricher, array $qIds, string $locale): array
    {
        $out = [];
        foreach ($enricher->enrich($qIds, $locale) as $row) {
            $out[$row['qid']] = $row['enrichment'];
        }

        return $out;
    }

    #[Test]
    public function parsesAllFieldsFromASparqlBinding(): void
    {
        $enricher = $this->enricher([$this->bindingsResponse([
            [
                'item' => ['value' => 'http://www.wikidata.org/entity/Q243'],
                'itemLabel' => ['value' => 'Tour Eiffel'],
                'itemDescription' => ['value' => 'tour de fer a Paris'],
                'image' => ['value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/Tour%20Eiffel.jpg'],
                'website' => ['value' => 'https://www.toureiffel.paris'],
                'openingHours' => ['value' => '09:00-23:00'],
                'article' => ['value' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel'],
            ],
        ])]);

        self::assertSame([
            'Q243' => [
                'label' => 'Tour Eiffel',
                'description' => 'tour de fer a Paris',
                'website' => 'https://www.toureiffel.paris',
                'openingHours' => '09:00-23:00',
                'wikipediaUrl' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel',
                'imageUrl' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Tour%20Eiffel.jpg?width=400',
            ],
        ], $this->collect($enricher, ['Q243'], 'fr'));
    }

    #[Test]
    public function yieldsANegativeMarkerForAQIdWithNoData(): void
    {
        // The batch succeeds (HTTP 200) but Wikidata returns no binding for the
        // Q-ID: it must still be yielded with a null enrichment so the caller can
        // negatively cache it and stop re-querying it.
        $enricher = $this->enricher([$this->bindingsResponse([])]);

        self::assertSame(['Q999' => null], $this->collect($enricher, ['Q999'], 'fr'));
    }

    #[Test]
    public function skipsInvalidQIdsAndSendsOnlySafeValues(): void
    {
        $captured = null;
        $enricher = $this->enricher([$this->bindingsResponse([])], function (string $method, string $url, array $options) use (&$captured): void {
            $query = $options['query'] ?? null;
            $captured = \is_array($query) && \is_string($query['query'] ?? null) ? $query['query'] : $url;
        });

        $result = $this->collect($enricher, ['Q1', 'not-a-qid', 'Q2; DROP TABLE'], 'en');

        self::assertSame(['Q1' => null], $result);
        self::assertIsString($captured);
        self::assertStringContainsString('wd:Q1', $captured);
        self::assertStringNotContainsString('DROP', $captured);
    }

    #[Test]
    public function yieldsNothingWhenNoQIds(): void
    {
        $enricher = $this->enricher([]);

        self::assertSame([], $this->collect($enricher, [], 'fr'));
    }

    #[Test]
    public function skipsABatchThatFailsEveryAttempt(): void
    {
        // Three persistent 500s (= MAX_ATTEMPTS): the batch is dropped entirely,
        // so its Q-IDs are not yielded (left uncached, retried next run) rather
        // than negatively cached after a transient outage.
        $enricher = $this->enricher([
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
        ]);

        self::assertSame([], $this->collect($enricher, ['Q243'], 'fr'));
    }

    #[Test]
    public function retriesAfterATransientFailureThenSucceeds(): void
    {
        $enricher = $this->enricher([
            new MockResponse('busy', ['http_code' => 503]),
            $this->bindingsResponse([
                ['item' => ['value' => 'http://www.wikidata.org/entity/Q243'], 'website' => ['value' => 'https://ok.test']],
            ]),
        ]);

        self::assertSame(['Q243' => ['website' => 'https://ok.test']], $this->collect($enricher, ['Q243'], 'fr'));
    }
}
