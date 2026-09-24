<?php

declare(strict_types=1);

namespace App\State\Mcp;

/**
 * Structural hygiene on text this project did not write, before it reaches a model.
 *
 * A trip title comes from Komoot or from the user. Place labels are reverse-geocoded from
 * OpenStreetMap. Accommodation names come from OSM or from whatever the user typed. All of it
 * is editable upstream by people this project has never met, and all of it lands in a model's
 * context.
 *
 * **This is not a prompt-injection defence, and must not be described as one.** It does not
 * look for instructions, because a blocklist of phrases gives false confidence and fails on
 * the first paraphrase. The full posture — delimiting third-party content as data, auditing
 * what the tool descriptions and error messages interpolate, and a test that puts a directive
 * string in a POI name and asserts it comes out inert — is unit 3C, and nothing here
 * anticipates it.
 *
 * What it does is narrower and actually holds: it stops a value from changing the *shape* of
 * the answer around it. A name carrying newlines can forge what looks like the end of one
 * field and the start of another; control characters can do worse in a terminal; and a name
 * long enough is a denial of service against the very budget the digest exists to respect.
 * None of that needs to know what the text means.
 *
 * **It is a label sanitiser, and it is applied only to labels.** That boundary is deliberate,
 * not an oversight, and the 200-character cap is why: a place name past that length is a
 * mistake or an attack, but an accommodation's Wikidata `description` or its opening hours
 * legitimately run longer, and cutting them would damage the data in the name of protecting
 * it. So the rich third-party structures a drill-down returns — {@see
 * \App\ApiResource\Model\Accommodation}, {@see \App\ApiResource\Model\Event}, and the alert
 * payloads their producers publish — pass through untouched.
 *
 * Which means per-field calls are the wrong long-term mechanism: every new field is a new
 * place to remember, and the coverage gap is invisible until someone reads for it. Unit 3C
 * owns the general answer, and it has to be one that applies to a whole payload — delimiting
 * third-party content as data at the point it is serialised — rather than one call site at a
 * time. Nothing here should grow into a half-version of that.
 */
final readonly class ThirdPartyText
{
    /**
     * Past this, it is not a name any more.
     *
     * OSM's own `name` values run to a few dozen characters; the longest place names in the
     * world are under 200. A value beyond this is either a mistake upstream or an attempt to
     * spend the reader's budget, and neither deserves the room.
     */
    private const int MAX_LENGTH = 200;

    public static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        // Two passes, because the two kinds of character mean opposite things — a unit test
        // caught this: collapsing everything to a space turned "Gre\u{0007}noble" into
        // "Gre noble", inventing a word break inside a name.
        //
        // Separators become one space: a label is a single line by definition, and welding
        // "Saint-Jean\nde-Maurienne" into one word would be its own corruption.
        $value = preg_replace('/[\r\n\t\p{Zl}\p{Zp}]+/u', ' ', $value) ?? $value;

        // Everything else in the control and format categories is simply not there — a BEL, a
        // zero-width space or a right-to-left override is not a word break, it is noise that
        // happens to sit between two letters.
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? $value;

        $value = trim(preg_replace('/ {2,}/u', ' ', $value) ?? $value);

        if ('' === $value) {
            return null;
        }

        return mb_strlen($value) > self::MAX_LENGTH
            ? rtrim(mb_substr($value, 0, self::MAX_LENGTH)).'…'
            : $value;
    }
}
