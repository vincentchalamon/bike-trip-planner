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

        // Newlines, tabs and the C0/C1 ranges. A label is a single line by definition, so
        // collapsing rather than stripping keeps "Saint-Jean\nde-Maurienne" readable instead
        // of welding two words together.
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/ {2,}/u', ' ', $value) ?? $value);

        if ('' === $value) {
            return null;
        }

        return mb_strlen($value) > self::MAX_LENGTH
            ? rtrim(mb_substr($value, 0, self::MAX_LENGTH)).'…'
            : $value;
    }
}
