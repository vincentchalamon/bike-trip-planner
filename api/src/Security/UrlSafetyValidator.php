<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Validates that a URL does not resolve to a private or reserved IP range.
 *
 * Prevents SSRF by resolving the hostname and rejecting RFC 1918, link-local,
 * loopback, and other non-routable addresses before the HTTP request is made.
 */
final class UrlSafetyValidator
{
    public function isPublicUrl(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return false;
        }

        $ips = gethostbynamel($host);
        if (false === $ips || [] === $ips) {
            return false;
        }

        return array_all($ips, fn ($ip): bool => false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE));
    }
}
