<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * Collapses near-duplicate places coming from several sources (OSM + DataTourisme).
 *
 * Two entries are the same place when they share a non-empty wikidata id, or when
 * their normalised names are equal and they sit within {@see PROXIMITY_METERS}.
 * The DataTourisme entry wins on a tie (curated name, opening hours, description).
 * Most flux objects carry no `owl:sameAs`, so the proximity+name pass is what
 * actually removes the OSM/DataTourisme doubles the wikidata key alone misses
 * (ADR-040). Each entry's full payload is preserved; callers re-pin the row shape.
 */
final readonly class NearbyNameDeduplicator
{
    private const int PROXIMITY_METERS = 75;

    private readonly ?\Transliterator $transliterator;

    public function __construct(private GeoDistanceInterface $haversine)
    {
        // Built once: dedupe() is O(n²) and normalizeName() runs twice per pair.
        $this->transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function dedupe(array $items): array
    {
        $kept = [];

        foreach ($items as $item) {
            $match = array_find_key($kept, fn (array $existing): bool => $this->isSamePlace($item, $existing));

            if (null === $match) {
                $kept[] = $item;

                continue;
            }

            // Same place from two sources, in its first-seen position: keep the
            // curated DataTourisme entry.
            if ('datatourisme' === ($item['source'] ?? null) && 'datatourisme' !== ($kept[$match]['source'] ?? null)) {
                $kept[$match] = $item;
            }
        }

        return array_values($kept);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function isSamePlace(array $a, array $b): bool
    {
        $wikidata = $a['wikidataId'] ?? null;
        if (\is_string($wikidata) && '' !== $wikidata && $wikidata === ($b['wikidataId'] ?? null)) {
            return true;
        }

        $name = $this->normalizeName($a);
        if ('' === $name || $name !== $this->normalizeName($b)) {
            return false;
        }

        return $this->haversine->inMeters(
            $this->coord($a, 'lat'),
            $this->coord($a, 'lon'),
            $this->coord($b, 'lat'),
            $this->coord($b, 'lon'),
        ) <= self::PROXIMITY_METERS;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalizeName(array $item): string
    {
        $name = $item['name'] ?? null;
        if (!\is_string($name)) {
            return '';
        }

        $ascii = $this->transliterator?->transliterate($name);

        return preg_replace('/[^a-z0-9]/', '', \is_string($ascii) ? $ascii : strtolower($name)) ?? '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function coord(array $item, string $key): float
    {
        $value = $item[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
