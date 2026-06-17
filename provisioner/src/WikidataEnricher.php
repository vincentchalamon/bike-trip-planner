<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Enriches reference places from Wikidata at provisioning time (ADR-040/041),
 * replacing the former runtime enrichment: a batched SPARQL query over the
 * bounded set of Q-IDs imported into PostGIS, returning label / description /
 * image / website / opening hours / Wikipedia URL per item.
 *
 * Streams one batch at a time (yields per Q-ID) so the caller never holds the
 * whole enrichment set in memory — the persistent cache, not a PHP map, is the
 * join source for the UPDATE (ADR-041 memory budget).
 *
 * Resilient (ADR-041): each batch is retried with backoff on transient failures
 * (HTTP 429/5xx, transport errors). A batch that still fails is simply skipped —
 * its Q-IDs are NOT yielded, so they stay uncached and are retried on the next
 * run rather than being negatively cached after a transient Wikidata outage.
 *
 * @phpstan-type Enrichment array{label?: string, description?: string, imageUrl?: string, website?: string, openingHours?: string, wikipediaUrl?: string}
 */
final readonly class WikidataEnricher
{
    private const int BATCH_SIZE = 50;

    private const int MAX_ATTEMPTS = 3;

    private const string SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private HttpClientInterface $httpClient;

    /**
     * @param (\Closure(int): void)|null $sleep injectable sleeper (seconds) so tests don't wait on backoff
     */
    public function __construct(
        ?HttpClientInterface $httpClient = null,
        private float $timeoutSeconds = 60.0,
        private ?\Closure $sleep = null,
    ) {
        // Wikidata's SPARQL endpoint throttles unidentified agents aggressively
        // (the generic Symfony default), and enrichment failures are tolerated as
        // best-effort, so a block would silently disable enrichment: send a
        // descriptive User-Agent per Wikidata's bot policy.
        $this->httpClient = $httpClient ?? ScopingHttpClient::forBaseUri(
            HttpClient::create([
                'max_redirects' => 2,
                'timeout' => $this->timeoutSeconds,
                'headers' => ['User-Agent' => 'BikeTripPlanner/1.0 (https://github.com/vincentchalamon/bike-trip-planner)'],
            ]),
            'https://query.wikidata.org/',
        );
    }

    /**
     * Yields one entry per Q-ID of every batch that completed, with its
     * enrichment (or null when Wikidata holds no data for it — a negative-cache
     * marker). Q-IDs of a batch that failed all attempts are not yielded.
     *
     * @param list<string> $qIds
     *
     * @return \Generator<int, array{qid: string, enrichment: Enrichment|null}>
     */
    public function enrich(array $qIds, string $locale): \Generator
    {
        foreach (array_chunk($qIds, self::BATCH_SIZE) as $batch) {
            $safeIds = array_values(array_filter($batch, static fn (string $id): bool => 1 === preg_match('/^Q\d+$/', $id)));
            if ([] === $safeIds) {
                continue;
            }

            $enrichments = $this->fetchBatch($safeIds, $locale);
            if (null === $enrichments) {
                continue;
            }

            foreach ($safeIds as $qId) {
                yield ['qid' => $qId, 'enrichment' => $enrichments[$qId] ?? null];
            }
        }
    }

    /**
     * @param list<string> $safeIds pre-validated Q-IDs (^Q\d+$)
     *
     * @return array<string, Enrichment>|null null when the batch failed every attempt
     */
    private function fetchBatch(array $safeIds, string $locale): ?array
    {
        $values = implode(' ', array_map(static fn (string $id): string => 'wd:'.$id, $safeIds));
        $lang = strtolower(substr($locale, 0, 2));

        $sparql = <<<SPARQL
            SELECT ?item ?itemLabel ?itemDescription ?image ?website ?openingHours ?article WHERE {
              VALUES ?item { {$values} }
              OPTIONAL { ?item wdt:P18 ?image. }
              OPTIONAL { ?item wdt:P856 ?website. }
              OPTIONAL { ?item wdt:P8989 ?openingHours. }
              OPTIONAL {
                ?article schema:about ?item ;
                         schema:isPartOf <https://{$lang}.wikipedia.org/>.
              }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "{$lang},en". }
            }
            SPARQL;

        $bindings = $this->query($sparql);

        return null === $bindings ? null : $this->parseBindings($bindings);
    }

    /**
     * @return list<array<string, array{value?: string}>>|null null when every attempt failed
     */
    private function query(string $sparql): ?array
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $response = $this->httpClient->request('GET', self::SPARQL_ENDPOINT, [
                    'query' => ['query' => $sparql, 'format' => 'json'],
                ]);

                $data = $response->toArray();

                $results = $data['results'] ?? null;
                $bindings = \is_array($results) ? ($results['bindings'] ?? null) : null;
                if (!\is_array($bindings)) {
                    return [];
                }

                /** @var list<array<string, array{value?: string}>> $list */
                $list = array_values(array_filter($bindings, is_array(...)));

                return $list;
            } catch (HttpClientExceptionInterface|\JsonException) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    return null;
                }

                // Exponential backoff (1s, 2s, …) before retrying a transient failure.
                $this->doSleep(2 ** ($attempt - 1));
            }
        }
    }

    /**
     * @param list<array<string, array{value?: string}>> $bindings
     *
     * @return array<string, Enrichment>
     */
    private function parseBindings(array $bindings): array
    {
        $enrichments = [];

        foreach ($bindings as $row) {
            $itemUri = $row['item']['value'] ?? null;
            if (!\is_string($itemUri)) {
                continue;
            }

            $qId = substr((string) strrchr($itemUri, '/'), 1);
            if (1 !== preg_match('/^Q\d+$/', $qId)) {
                continue;
            }

            /** @var Enrichment $entry */
            $entry = $enrichments[$qId] ?? [];

            $entry['label'] ??= $this->stringField($row, 'itemLabel');
            $entry['description'] ??= $this->stringField($row, 'itemDescription');
            $entry['website'] ??= $this->stringField($row, 'website');
            $entry['openingHours'] ??= $this->stringField($row, 'openingHours');
            $entry['wikipediaUrl'] ??= $this->stringField($row, 'article');

            $image = $this->stringField($row, 'image');
            if (null !== $image && !isset($entry['imageUrl'])) {
                $entry['imageUrl'] = $this->buildCommonsThumbUrl($image);
            }

            $enrichments[$qId] = array_filter($entry, static fn (?string $v): bool => null !== $v);
        }

        return $enrichments;
    }

    /**
     * @param array<string, array{value?: string}> $row
     */
    private function stringField(array $row, string $key): ?string
    {
        $value = $row[$key]['value'] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Wikimedia Commons file URI -> direct 400 px thumbnail URL.
     */
    private function buildCommonsThumbUrl(string $fileUri): string
    {
        $cleaned = str_replace('http://', 'https://', $fileUri);
        $separator = str_contains($cleaned, '?') ? '&' : '?';

        return $cleaned.$separator.'width=400';
    }

    private function doSleep(int $seconds): void
    {
        if ($this->sleep instanceof \Closure) {
            ($this->sleep)($seconds);

            return;
        }

        sleep($seconds);
    }
}
