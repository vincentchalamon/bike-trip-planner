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
 *
 * Only a name the source actually carries counts: callers MUST NOT pass a label
 * derived from the category, otherwise every anonymous cafe of a village centre
 * shares one name and all but the first are dropped (issue #874). An entry with
 * no name is therefore never merged on the name pass, and its display label is
 * resolved after deduplication ({@see \App\Poi\PoiLabelResolver}).
 */
final readonly class NearbyNameDeduplicator
{
    private const int PROXIMITY_METERS = 75;

    private ?\Transliterator $transliterator;

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
                $kept[$match] = $this->withoutLosingKnownValues($item, $kept[$match]);
            }
        }

        return array_values($kept);
    }

    /**
     * The curated entry wins, but preferring a source must not destroy what the
     * other one knew: the flux carries no website for cultural and food POIs, so
     * a plain overwrite dropped the OSM website for every place both sources
     * describe — precisely the ones a rider is most likely to look up.
     *
     * Only keys the winner leaves null are backfilled, so the winner never loses
     * a value, and no key it does not declare is invented.
     *
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $loser
     *
     * @return array<string, mixed>
     */
    private function withoutLosingKnownValues(array $winner, array $loser): array
    {
        foreach ($winner as $key => $value) {
            if (null === $value && null !== ($loser[$key] ?? null)) {
                $winner[$key] = $loser[$key];
            }
        }

        return $winner;
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

        $latA = $this->coord($a, 'lat');
        $lonA = $this->coord($a, 'lon');
        $latB = $this->coord($b, 'lat');
        $lonB = $this->coord($b, 'lon');
        if (\in_array(null, [$latA, $lonA, $latB, $lonB], true)) {
            return false;
        }

        return $this->haversine->inMeters($latA, $lonA, $latB, $lonB) <= self::PROXIMITY_METERS;
    }

    /**
     * Empty string for an entry carrying no proper name, which makes
     * {@see isSamePlace()} bail: two anonymous entries are never collapsed.
     *
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
    private function coord(array $item, string $key): ?float
    {
        $value = $item[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
