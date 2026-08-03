<?php

declare(strict_types=1);

namespace App\Accommodation;

/**
 * Ranks the accommodation candidates of one stage and keeps the best few.
 *
 * Completeness first, price second. Ordering on price alone (the pre-#869
 * behaviour) systematically retained the cheapest categories, because the price
 * of most candidates comes from PricingHeuristicEngine (type + tags) and not from
 * a real offer: shelters and camp sites always won on their low heuristic floor,
 * and the rich, bookable entries never made the cut. Completeness ranks on
 * signals that are actually observed in the index — website, description, opening
 * hours, wikidata Q-ID, stars, capacity, and (for OSM) tag richness.
 *
 * Source-bias guard: completeness alone favours DataTourisme, whose entries are
 * always named and usually described, and OSM camp sites — sparse by nature —
 * would be evicted from every stage. The guard is a **per-family cap** of
 * `limit - 1`: at least one slot always goes to the other family when it has any
 * candidate, so a stage offering camp sites and hotels never returns only one of
 * the two. A per-*source* cap was rejected: DataTourisme is France-only, so it
 * would be a no-op outside France, where the eviction risk is identical (the
 * families come from both sources). A per-*type* cap was rejected too: hotel +
 * guest_house + motel are three distinct types but one family, and the camp site
 * would still be evicted.
 *
 * Reproducibility: the comparator is a total order up to (score, priceMin) ties,
 * and PHP's sort has been stable since 8.0, so ties keep the caller's order —
 * which the repositories make deterministic (KNN order, primary-key tiebreak,
 * bounded LIMIT; #868). Two runs of the same scan therefore retain the same set
 * in the same order.
 */
final readonly class CandidateRanker
{
    private const int WEIGHT_WEBSITE = 3;

    private const int WEIGHT_DESCRIPTION = 3;

    private const int WEIGHT_OPENING_HOURS = 2;

    private const int WEIGHT_WIKIDATA = 2;

    private const int WEIGHT_STARS = 2;

    private const int WEIGHT_CAPACITY = 1;

    /** OSM tags per completeness point, and the cap on that contribution. */
    private const int TAGS_PER_POINT = 3;

    /**
     * Tag richness is capped so it stays a tiebreaker: it is an OSM-only signal
     * (DataTourisme publishes fields, not tags) and must not outweigh a described,
     * bookable entry on its own.
     */
    private const int MAX_TAG_POINTS = 2;

    /**
     * Minimal-facility types, poor in metadata by nature: they lose the
     * completeness ranking and are what the family cap protects.
     */
    private const array OUTDOOR_TYPES = ['camp_site', 'shelter', 'wilderness_hut'];

    /**
     * @param list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, stars?: ?int, capacity?: ?int, fee?: ?string, source?: string, wikidataId?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string}> $candidates
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, stars?: ?int, capacity?: ?int, fee?: ?string, source?: string, wikidataId?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string}>
     */
    public function rank(array $candidates, int $limit): array
    {
        $ranked = $candidates;
        usort(
            $ranked,
            fn (array $a, array $b): int => $this->completeness($b) <=> $this->completeness($a)
                ?: $a['priceMin'] <=> $b['priceMin'],
        );

        $perFamily = max(1, $limit - 1);
        $kept = [];
        $counts = [];
        $capped = [];

        foreach ($ranked as $candidate) {
            $family = $this->family($candidate['type']);
            if (($counts[$family] ?? 0) >= $perFamily) {
                $capped[] = $candidate;
                continue;
            }

            $kept[] = $candidate;
            $counts[$family] = ($counts[$family] ?? 0) + 1;

            if (\count($kept) === $limit) {
                return $kept;
            }
        }

        // Only one family around this stage: the reserved slot has nothing to hold,
        // so give it back to the ranking instead of returning fewer candidates.
        foreach ($capped as $candidate) {
            if (\count($kept) === $limit) {
                break;
            }

            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * @param array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, stars?: ?int, capacity?: ?int, fee?: ?string, source?: string, wikidataId?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string} $candidate
     */
    private function completeness(array $candidate): int
    {
        $score = 0;

        if ($candidate['hasWebsite'] || null !== $candidate['url']) {
            $score += self::WEIGHT_WEBSITE;
        }

        if ($this->filled($candidate['description'] ?? null)) {
            $score += self::WEIGHT_DESCRIPTION;
        }

        if ($this->filled($candidate['openingHours'] ?? null)) {
            $score += self::WEIGHT_OPENING_HOURS;
        }

        if ($this->filled($candidate['wikidataId'] ?? null)) {
            $score += self::WEIGHT_WIKIDATA;
        }

        if (null !== ($candidate['stars'] ?? null)) {
            $score += self::WEIGHT_STARS;
        }

        if (null !== ($candidate['capacity'] ?? null)) {
            $score += self::WEIGHT_CAPACITY;
        }

        return $score + min(self::MAX_TAG_POINTS, intdiv($candidate['tagCount'], self::TAGS_PER_POINT));
    }

    private function family(string $type): string
    {
        return \in_array($type, self::OUTDOOR_TYPES, true) ? 'outdoor' : 'indoor';
    }

    private function filled(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }
}
