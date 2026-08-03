<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Normalises a hand-typed website value into an absolute http(s) URL, or null
 * when it is not one, so the imported columns are clean at rest.
 *
 * The DataTourisme flux publishes `foaf:homepage` as free text: an office de
 * tourisme types "www.gite.fr" (schema-less, which a browser resolves relative
 * to the app origin), "nous contacter", or an e-mail address. Storing those
 * verbatim pushes the problem to every consumer, so an unusable value is stored
 * NULL instead (#872).
 *
 * Rules, deliberately conservative:
 *
 * - a missing scheme is assumed to be `https`, and so is a `//host/path`;
 * - any scheme other than http/https (`mailto:`, `tel:`, `javascript:`) is out;
 * - userinfo is rejected, which is what an e-mail becomes once absolutised;
 * - the host must be a dotted name, so "nous contacter" and "localhost" are out;
 * - scheme and host are lowercased, the rest of the URL is preserved verbatim.
 *
 * Mirrored by {@see \App\Format\WebsiteUrl} on the API side, which guards the
 * read path; the two live in separate Composer packages and cannot share code.
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
