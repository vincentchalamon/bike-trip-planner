<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Resolves a readable name for an accommodation that arrives without one, before the
 * completeness gate decides whether to keep it (ADR-049 §3, issue #884).
 *
 * **The cascade is short because the measurement says it should be.** #884 sketched five
 * tag keys; #878 counted them over 16 886 rows on nord-pas-de-calais + rhone-alpes and
 * found `name:fr` **0** occurrences, `official_name` **0**, `alt_name` 11 (every one of
 * them "Salle hors-sac", i.e. not a name), `brand` 1 — the whole fallback resting on
 * `operator`, of which **184 of 199 values on `shelter` are transport operators**
 * (JCDecaux, Transdev, STAS, S.N.C.F., Keolis). A five-key resolver would be code with no
 * data behind it. What is left, and what this implements: `operator` then `brand`, never
 * on `shelter`.
 *
 * Order of the cascade, most specific first: a **geometric match against DataTourisme**
 * (#885) names *this* place from a curated record; a Wikidata label names the entity; then
 * `operator` and `brand`, which name whoever runs it. #884 ordered these by cost ("du moins
 * coûteux au plus coûteux"), but by the time this runs both the Wikidata cache and the flux
 * staging are already loaded, so every step costs zero network calls and the cost argument
 * no longer separates them. Specificity does.
 *
 * The match is where the loop #885 describes gets broken. `App\Geo\NearbyNameDeduplicator`
 * pairs places at runtime **by name**, so it can never complete a row whose name is missing:
 * the only information lacking is the one it requires to find the correspondence. At import
 * there is no such constraint — the full context of both datasets is in hand, and nobody is
 * waiting — so category plus proximity is enough.
 *
 * `shelter` never reaches this class: it is exempt from the gate (#878 — `shelter_type`, not
 * the name, is what separates a mountain refuge from a bus shelter), so resolving it would
 * only attach a carrier's name to street furniture.
 */
final readonly class NameResolver
{
    /**
     * Bumped when this class starts resolving something it used to reject. Stored per entry
     * in `provisioner.place_enrichment`, which is what lets a later resolver reconsider the
     * rows it rejected: without it, every zone would stay at the data quality of the day it
     * was opened (ADR-049 §4).
     */
    public const int VERSION = 1;

    /**
     * Values that are a tag rather than a name. `yes` is the common one — the tag says a
     * feature is operated, not by whom — and the transport operators are what #878 found
     * behind almost every `operator` on `shelter`. They are listed here rather than relied
     * on being filtered by the shelter exemption alone, because a bus company still names
     * no campsite.
     *
     * Matched case-insensitively and ignoring punctuation, since #878's first count missed
     * `S.N.C.F.` (9 rows) for exactly that reason.
     *
     * @var list<string>
     */
    private const array NOT_A_NAME = [
        'yes', 'no', 'unknown', 'private', 'public', 'none',
        'jcdecaux', 'transdev', 'stas', 'sncf', 'keolis', 'ratp', 'ter', 'tcl', 'oui',
    ];

    /**
     * Shortest value worth using. Two characters cannot identify a place to a rider, and
     * this is what stops an initial or a stray code from passing the gate.
     */
    private const int MIN_LENGTH = 3;

    /**
     * @param array<string, string>                                                                                                              $tags          the row's OSM tags, complete as imported
     * @param string|null                                                                                                                        $wikidataLabel label already in provisioner.wikidata_cache for this row's Q-ID
     * @param string|null                                                                                                                        $locality      commune resolved offline from the imported admin_level=8 boundaries (#880)
     * @param array{n: int, id: ?string, name: ?string, description: ?string, website: ?string, opening_hours: ?string, distance_m: ?float}|null $match         curated candidates of a compatible category within the match radius (#885)
     *
     * @return array{name: string, via: string, description?: string, website?: string, opening_hours?: string, matched_id?: string, distance_m?: float}|array{name: null, via: null, reason: string, candidates?: int} the resolved name plus what came with it, or the motive for giving up
     */
    public function resolve(string $category, array $tags, ?string $wikidataLabel = null, ?string $locality = null, ?array $match = null): array
    {
        if ('shelter' === $category) {
            // Exempt from the gate, so nothing to resolve; see the class docblock.
            return ['name' => null, 'via' => null, 'reason' => 'shelter_exempt'];
        }

        if (null !== $match) {
            $matched = $this->fromMatch($match);
            if (null !== $matched) {
                return $matched;
            }

            // More than one curated candidate in range: refuse rather than pick. Attributing
            // the wrong name to an accommodation is worse than attributing none — the rider
            // books elsewhere, or turns up at the wrong place — and nothing here can tell two
            // neighbouring campsites apart. The gate decides what happens next.
            if ($match['n'] > 1) {
                return ['name' => null, 'via' => null, 'reason' => 'ambiguous_match', 'candidates' => $match['n']];
            }
        }

        foreach (['wikidata' => $wikidataLabel, 'operator' => $tags['operator'] ?? null, 'brand' => $tags['brand'] ?? null] as $via => $candidate) {
            $name = $this->usable($candidate);
            if (null === $name) {
                continue;
            }

            return ['name' => $this->qualify($name, $locality), 'via' => $via];
        }

        return ['name' => null, 'via' => null, 'reason' => 'no_usable_name_source'];
    }

    /**
     * The single curated candidate's name, with what usefully comes with it, or null when
     * there is not exactly one usable candidate.
     *
     * @param array{n: int, id: ?string, name: ?string, description: ?string, website: ?string, opening_hours: ?string, distance_m: ?float} $match
     *
     * @return array{name: string, via: string, description?: string, website?: string, opening_hours?: string, matched_id?: string, distance_m?: float}|null
     */
    private function fromMatch(array $match): ?array
    {
        if (1 !== $match['n']) {
            return null;
        }

        $name = $this->usable($match['name']);
        if (null === $name) {
            return null;
        }

        // No locality qualifier here: a curated record is already named the way the place
        // presents itself, so appending the commune would only add noise.
        $resolved = ['name' => $name, 'via' => 'datatourisme'];
        foreach (['description', 'website', 'opening_hours'] as $field) {
            $value = $match[$field];
            if (\is_string($value) && '' !== trim($value)) {
                $resolved[$field] = trim($value);
            }
        }

        if (\is_string($match['id'])) {
            $resolved['matched_id'] = $match['id'];
        }

        if (null !== $match['distance_m']) {
            $resolved['distance_m'] = $match['distance_m'];
        }

        return $resolved;
    }

    /**
     * A candidate value, or null when it is a tag rather than a name.
     */
    private function usable(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);
        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            return null;
        }

        // Punctuation-insensitive, so `S.N.C.F.` and `SNCF` are the same value.
        $normalised = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $trimmed));

        return \in_array($normalised, self::NOT_A_NAME, true) ? null : $trimmed;
    }

    /**
     * Appends the commune when it adds information, which is what turns a generic
     * "Camping municipal" into "Camping municipal — Sarlat". Skipped when the locality is
     * already in the name, so a place named after its village is not repeated.
     */
    private function qualify(string $name, ?string $locality): string
    {
        if (null === $locality || '' === trim($locality)) {
            return $name;
        }

        $locality = trim($locality);
        if (false !== mb_stripos($name, $locality)) {
            return $name;
        }

        return \sprintf('%s — %s', $name, $locality);
    }
}
