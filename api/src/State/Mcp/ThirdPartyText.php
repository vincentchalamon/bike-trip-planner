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
 * the first paraphrase. Unit 3C kept that line rather than crossing it: what bounds a
 * successful injection is the token's scope and the ownership check on every tool, and what
 * the server owes a model is that third-party text never reaches the channels it reads as
 * instructions — tool descriptions and error messages (ADR-081).
 *
 * What it does is narrower and actually holds: it stops a value from changing the *shape* of
 * the answer around it. A name carrying newlines can forge what looks like the end of one
 * field and the start of another; control characters can do worse in a terminal; and a name
 * long enough is a denial of service against the very budget the digest exists to respect.
 * None of that needs to know what the text means.
 *
 * **It is a label sanitiser, and it is applied to labels.** That boundary is deliberate, and
 * the 200-character cap is why: a place name past that length is a mistake or an attack, but
 * an accommodation's Wikidata `description` or its opening hours legitimately run longer, and
 * cutting them would damage the data in the name of protecting it. So a `name` is cleaned
 * wherever one appears — the trip title, a stage's labels, an accommodation's, an event's —
 * and the prose beside it is not.
 *
 * Coverage is not this class's job any more. Per-field calls are a list, and a list is what
 * gets forgotten — the alert payloads, published by their producers in whatever shape they
 * chose, went uncleaned for exactly that reason. {@see \App\Serializer\Mcp\McpTextFloor}
 * applies {@see self::hygiene()} to every string of every MCP answer where it is serialised.
 * What remains here is what the floor cannot know: that a field is a label, so a cap applies,
 * and that a label left with nothing in it is absent rather than blank.
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

        // A label is one tidy line: runs of spaces squeezed, nothing at either end.
        $value = trim(preg_replace('/ {2,}/u', ' ', self::hygiene($value)) ?? $value);

        if ('' === $value) {
            return null;
        }

        return mb_strlen($value) > self::MAX_LENGTH
            ? rtrim(mb_substr($value, 0, self::MAX_LENGTH)).'…'
            : $value;
    }

    /**
     * The structural half alone, and nothing cosmetic: no cap, no trimming, no squeezing, and a
     * string stays a string even when nothing is left.
     *
     * What {@see \App\Serializer\Mcp\McpTextFloor} applies to every string an MCP tool emits.
     * It cannot tell a name from a description or an identifier, so it may only remove what
     * changes the shape of an answer — never tidy what does not. A string with no control
     * character and no line break comes out byte for byte.
     */
    public static function hygiene(string $value): string
    {
        // Two passes, because the two kinds of character mean opposite things — a unit test
        // caught this: collapsing everything to a space turned "Gre\u{0007}noble" into
        // "Gre noble", inventing a word break inside a name.
        //
        // Separators become one space: a line break is what lets a value forge the end of one
        // field and the start of another, and welding "Saint-Jean\nde-Maurienne" into one word
        // would be its own corruption.
        $value = preg_replace('/[\r\n\t\p{Zl}\p{Zp}]+/u', ' ', $value) ?? $value;

        // Everything else in the control and format categories is simply not there — a BEL, a
        // zero-width space or a right-to-left override is not a word break, it is noise that
        // happens to sit between two letters.
        return preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value) ?? $value;
    }
}
