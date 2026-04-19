<?php

declare(strict_types=1);

namespace App\Wikidata;

final readonly class WikidataEnricher implements WikidataEnricherInterface
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private WikidataClientInterface $client,
    ) {
    }

    /**
     * @param list<string> $qIds
     *
     * @return array<string, array{label?: string, description?: string, imageUrl?: string, website?: string, openingHours?: string, wikipediaUrl?: string}>
     */
    public function enrichBatch(array $qIds, string $locale): array
    {
        if ([] === $qIds) {
            return [];
        }

        $result = [];
        $batches = array_chunk($qIds, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $batchResult = $this->fetchBatch($batch, $locale);
            $result = array_merge($result, $batchResult);
        }

        return $result;
    }

    /**
     * @param list<string> $qIds
     *
     * @return array<string, array{label?: string, description?: string, imageUrl?: string, website?: string, openingHours?: string, wikipediaUrl?: string}>
     */
    private function fetchBatch(array $qIds, string $locale): array
    {
        $safeIds = array_values(array_filter($qIds, static fn (string $id): bool => (bool) preg_match('/^Q\d+$/', $id)));
        if ([] === $safeIds) {
            return [];
        }

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

        $bindings = $this->client->query($sparql);

        $enrichments = [];

        foreach ($bindings as $row) {
            $itemUri = $row['item']['value'] ?? null;
            if (!\is_string($itemUri)) {
                continue;
            }

            $parts = explode('/', $itemUri);
            $qId = end($parts);
            if (!\is_string($qId) || !str_starts_with($qId, 'Q')) {
                continue;
            }

            $entry = $enrichments[$qId] ?? [];

            $label = $row['itemLabel']['value'] ?? null;
            if (\is_string($label) && '' !== $label && !isset($entry['label'])) {
                $entry['label'] = $label;
            }

            $description = $row['itemDescription']['value'] ?? null;
            if (\is_string($description) && '' !== $description && !isset($entry['description'])) {
                $entry['description'] = $description;
            }

            $image = $row['image']['value'] ?? null;
            if (\is_string($image) && '' !== $image && !isset($entry['imageUrl'])) {
                $entry['imageUrl'] = $this->buildCommonsThumbUrl($image);
            }

            $website = $row['website']['value'] ?? null;
            if (\is_string($website) && '' !== $website && !isset($entry['website'])) {
                $entry['website'] = $website;
            }

            $openingHours = $row['openingHours']['value'] ?? null;
            if (\is_string($openingHours) && '' !== $openingHours && !isset($entry['openingHours'])) {
                $entry['openingHours'] = $openingHours;
            }

            $article = $row['article']['value'] ?? null;
            if (\is_string($article) && '' !== $article && !isset($entry['wikipediaUrl'])) {
                $entry['wikipediaUrl'] = $article;
            }

            $enrichments[$qId] = $entry;
        }

        return $enrichments;
    }

    /**
     * Converts a Wikimedia Commons file URI to a direct thumbnail URL (400 px wide).
     *
     * Uses the standard Wikimedia Commons thumb URL format.
     * See https://www.mediawiki.org/wiki/Manual:$wgHashedUploadDirectory
     */
    private function buildCommonsThumbUrl(string $fileUri): string
    {
        // fileUri looks like: http://commons.wikimedia.org/wiki/Special:FilePath/Foo.jpg
        // We want: https://commons.wikimedia.org/wiki/Special:FilePath/Foo.jpg?width=400
        $cleaned = str_replace('http://', 'https://', $fileUri);
        $separator = str_contains($cleaned, '?') ? '&' : '?';

        return $cleaned.$separator.'width=400';
    }
}
