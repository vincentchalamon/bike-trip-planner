<?php

declare(strict_types=1);

namespace App\Format;

/**
 * Normalises a hand-typed website value into an absolute http(s) URL, or null
 * when it is not one.
 *
 * Both source sets are free text: the DataTourisme flux publishes whatever the
 * office de tourisme typed in `foaf:homepage`, and OSM `website` /
 * `contact:website` are no better. So the value reaching the rider ranges from
 * "www.gite.fr" (schema-less, which a browser resolves relative to the app
 * origin) to "nous contacter" or "contact@gite.fr". Normalising here, on the
 * server, keeps the frontend guard (sprint 45) as a second line of defence
 * rather than the only one.
 *
 * Rules, deliberately conservative — an unusable value becomes null instead of
 * being passed through:
 *
 * - a missing scheme is assumed to be `https`, and so is a `//host/path`;
 * - any scheme other than http/https (`mailto:`, `tel:`, `javascript:`, `data:`)
 *   is rejected;
 * - userinfo is rejected, which is what an e-mail typed in a website field
 *   becomes once absolutised (`https://contact@gite.fr`);
 * - the host must be a dotted name, so "nous contacter" and "localhost" are out;
 * - scheme and host are lowercased, the rest of the URL is preserved verbatim.
 *
 * The provisioner has its own copy ({@see \Provisioner\WebsiteUrl}) because it
 * is a separate Composer package: it normalises at import time so the column is
 * clean at rest, while this one guards the read path (raw `tags` fallbacks, rows
 * loaded before the flux was normalised, OSM tags which are indexed verbatim).
 */
final class WebsiteUrl
{
    /** Scheme prefix, e.g. `https://`, `mailto:`. */
    private const string SCHEME_PATTERN = '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#';

    /** Dotted host name with a 2+ letter TLD; accented (IDN) labels allowed. */
    private const string HOST_PATTERN = '/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}\-]*[\p{L}\p{N}])?\.)+\p{L}{2,}$/u';

    public static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $candidate = trim($value);
        // A free-text answer ("nous contacter", "voir la page Facebook") only ever
        // yields a host with a space in it, which no absolutisation can rescue.
        if ('' === $candidate || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $candidate)) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:'.$candidate;
        } elseif (1 !== preg_match(self::SCHEME_PATTERN, $candidate)) {
            $candidate = 'https://'.$candidate;
        }

        $parts = parse_url($candidate);
        if (false === $parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (1 !== preg_match(self::HOST_PATTERN, $host)) {
            return null;
        }

        $url = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
