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
 * Order of the cascade. #884 lists the tag projection before the Wikidata label, ordered
 * "du moins coûteux au plus coûteux" — but by the time this runs, the Wikidata pass has
 * already populated its cache, so both steps cost zero network calls and the cost argument
 * no longer separates them. Quality does: a Wikidata label is the place's own name, while
 * `operator` is whoever runs it ("Commune de Jongieux"). The label therefore comes first.
 * The choice is nearly moot in practice — an unnamed row carrying a Q-ID is rare — which is
 * why it is a stated preference rather than a load-bearing decision.
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
     * @param array<string, string> $tags          the row's OSM tags, complete as imported
     * @param string|null           $wikidataLabel label already in provisioner.wikidata_cache for this row's Q-ID
     * @param string|null           $locality      commune resolved offline from the imported admin_level=8 boundaries (#880)
     *
     * @return array{name: string, via: string}|array{name: null, via: null, reason: string} the resolved name and which step produced it, or the motive for giving up
     */
    public function resolve(string $category, array $tags, ?string $wikidataLabel = null, ?string $locality = null): array
    {
        if ('shelter' === $category) {
            // Exempt from the gate, so nothing to resolve; see the class docblock.
            return ['name' => null, 'via' => null, 'reason' => 'shelter_exempt'];
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
