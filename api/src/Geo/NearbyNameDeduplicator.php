<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * Collapses near-duplicate places coming from several sources (OSM + DataTourisme).
 *
 * Two entries are the same place when they share a non-null wikidata id, or when
 * their normalised names are equal and they sit within {@see PROXIMITY_METERS}.
 * The DataTourisme entry wins on a tie (curated name, opening hours, description),
 * mirroring the previous wikidata-only preference. Most flux objects carry no
 * `owl:sameAs`, so the spatial+name pass is what actually removes the OSM/DataTourisme
 * doubles that the wikidata key alone misses (ADR-040).
 */
final readonly class NearbyNameDeduplicator
{
    private const int PROXIMITY_METERS = 75;

    public function __construct(private GeoDistanceInterface $haversine)
    {
    }

    /**
     * @template T of array{name: string, lat: float, lon: float, wikidataId: string|null, source: string}
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public function dedupe(array $items): array
    {
        /** @var list<T> $kept */
        $kept = [];

        foreach ($items as $item) {
            $match = null;
            foreach ($kept as $index => $existing) {
                if ($this->isSamePlace($item, $existing)) {
                    $match = $index;
                    break;
                }
            }

            if (null === $match) {
                $kept[] = $item;

                continue;
            }

            // Same place from two sources: keep the curated DataTourisme entry.
            if ('datatourisme' === $item['source'] && 'datatourisme' !== $kept[$match]['source']) {
                $kept[$match] = $item;
            }
        }

        return array_values($kept);
    }

    /**
     * @param array{name: string, lat: float, lon: float, wikidataId: string|null, source: string} $a
     * @param array{name: string, lat: float, lon: float, wikidataId: string|null, source: string} $b
     */
    private function isSamePlace(array $a, array $b): bool
    {
        if (null !== $a['wikidataId'] && $a['wikidataId'] === $b['wikidataId']) {
            return true;
        }

        $name = $this->normalize($a['name']);
        if ('' === $name || $name !== $this->normalize($b['name'])) {
            return false;
        }

        return $this->haversine->inMeters($a['lat'], $a['lon'], $b['lat'], $b['lon']) <= self::PROXIMITY_METERS;
    }

    private function normalize(string $name): string
    {
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $ascii = $transliterator?->transliterate($name);

        return preg_replace('/[^a-z0-9]/', '', false !== $ascii && null !== $ascii ? $ascii : strtolower($name)) ?? '';
    }
}
