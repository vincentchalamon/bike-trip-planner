<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An HTTP client confined to one origin with at most 2 redirects: the SSRF policy every
 * provisioner download follows (see CLAUDE.md).
 */
final class ScopedHttpClient
{
    /**
     * @param array<string, mixed> $options merged over the redirect cap
     */
    public static function create(string $baseUri, array $options): HttpClientInterface
    {
        return ScopingHttpClient::forBaseUri(HttpClient::create(['max_redirects' => 2] + $options), $baseUri);
    }
}
