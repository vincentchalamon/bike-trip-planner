<?php

declare(strict_types=1);

namespace App\Format;

/**
 * Reads the OSM contact block, which is written under two competing schemes:
 * the bare keys (`phone`, `website`) and the `contact:` namespace
 * (`contact:phone`, `contact:website`), plus `url` as a third spelling of the
 * website. Both live side by side in the wild, so a mapper who only filled
 * `contact:phone` is invisible to a reader that looks up `phone` alone.
 *
 * Static, without DI, like {@see WebsiteUrl}: this is a pure tag lookup, and
 * both the in-ride assistant (raw Overpass tags) and the planned path (the
 * `tags` jsonb of the Tier-1 index) call it on plain arrays.
 */
final class OsmContactTags
{
    /** @var list<string> */
    private const array PHONE_KEYS = ['phone', 'contact:phone'];

    /** @var list<string> */
    private const array WEBSITE_KEYS = ['website', 'contact:website', 'url', 'contact:url'];

    /**
     * First non-empty phone number of the contact block, verbatim.
     *
     * Not reformatted: OSM holds international, national and free-text spellings,
     * and a `tel:` link works with all of them.
     *
     * @param array<string, mixed> $tags
     */
    public static function phone(array $tags): ?string
    {
        return self::firstNonEmpty($tags, self::PHONE_KEYS);
    }

    /**
     * First website of the contact block that normalises to a usable http(s)
     * URL. A key holding free text ("nous contacter") is skipped rather than
     * shadowing a valid `contact:url` further down the list.
     *
     * @param array<string, mixed> $tags
     */
    public static function website(array $tags): ?string
    {
        foreach (self::WEBSITE_KEYS as $key) {
            $url = WebsiteUrl::normalize(self::firstNonEmpty($tags, [$key]));
            if (null !== $url) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tags
     * @param list<string>         $keys
     */
    private static function firstNonEmpty(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $tags[$key] ?? null;
            if (\is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return null;
    }
}
