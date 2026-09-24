<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a client id that is a URL into the metadata document it names (ADR-079).
 *
 * This is the one place where the application fetches an address a third party supplied, and
 * the existing SSRF pattern does not transpose: every other outbound call goes through a
 * client locked to a `base_uri`, with a numeric id captured by an anchored regex dropped into
 * a fixed path. Here the host is the input.
 *
 * What replaces it, in order:
 *
 *  1. The URL is judged before anything is opened — https, a path, no fragment, no userinfo,
 *     not our own host, and short enough to be stored.
 *  2. The request is made by a client that follows no redirect, gives up quickly, and is
 *     wrapped in {@see \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient}, which
 *     rejects on the IP actually connected to rather than on a name resolved beforehand.
 *  3. The body is read through a ceiling, not read and then measured.
 *  4. The document has to name itself: its `client_id` must equal the URL it came from, or
 *     any page on any host could claim any client id.
 *
 * Nothing here reports why it refused. A caller learning that a host timed out, resolved to a
 * private address, or answered with the wrong content type would be using the authorization
 * endpoint as a network scanner.
 */
final readonly class ClientMetadataResolver
{
    /**
     * Enough for a metadata document that declares a handful of redirect URIs, and small
     * enough that a hostile server cannot make a worker read for long. Enforced on the
     * stream: a `Content-Length` is a claim, not a limit.
     */
    private const int MAX_DOCUMENT_BYTES = 64 * 1024;

    /** A client that needs more than this many callback addresses is not one we can show. */
    private const int MAX_REDIRECT_URIS = 10;

    private const int MAX_CLIENT_NAME_LENGTH = 128;

    /**
     * The loopback literals RFC 8252 §7.3 allows over plain HTTP. `localhost` is deliberately
     * absent: it resolves through DNS, so it can be pointed somewhere else, and league treats
     * it as an ordinary host anyway ({@see \League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator::isLoopbackUri()}).
     */
    private const array LOOPBACK_HOSTS = ['127.0.0.1', '[::1]'];

    /** A document that asks to be remembered for a year is not humoured. */
    private const int MAX_CACHE_SECONDS = 86400;

    private const int MIN_CACHE_SECONDS = 300;

    public function __construct(
        #[Autowire(service: 'oauth_client_metadata.client')]
        private HttpClientInterface $client,
        #[Autowire(service: 'cache.oauth_client_metadata')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $ownUri,
    ) {
    }

    /**
     * @throws ClientMetadataRejected
     */
    public function resolve(string $clientId): ClientMetadata
    {
        $this->assertAddressable($clientId);

        $item = $this->cache->getItem(hash('sha256', $clientId));
        $cached = $item->get();
        if ($cached instanceof ClientMetadata) {
            return $cached;
        }

        try {
            $response = $this->client->request('GET', $clientId, [
                'on_progress' => static function (int $downloaded, int $total, array $info): void {
                    if ($downloaded > self::MAX_DOCUMENT_BYTES || $total > self::MAX_DOCUMENT_BYTES) {
                        throw new ClientMetadataRejected(\sprintf('Metadata document at "%s" is too large.', $info['url'] ?? ''));
                    }
                },
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new ClientMetadataRejected(\sprintf('Metadata document at "%s" answered %d.', $clientId, $response->getStatusCode()));
            }

            $body = $response->getContent(false);
            $cacheControl = implode(', ', $response->getHeaders(false)['cache-control'] ?? []);
        } catch (ClientMetadataRejected $rejected) {
            throw $this->reject($clientId, $rejected->getMessage());
        } catch (HttpExceptionInterface $transport) {
            // NoPrivateNetworkHttpClient throws from inside the progress callback when the
            // connection lands on a private address, mid-transfer. That arrives here as a
            // transport failure like any other, and must not escape as a 500.
            throw $this->reject($clientId, 'Metadata document could not be fetched: '.$transport->getMessage());
        }

        $metadata = $this->parse($clientId, $body);

        $item->set($metadata)->expiresAfter($this->lifetimeFrom($cacheControl));
        $this->cache->save($item);

        return $metadata;
    }

    /**
     * Honours the document's own `max-age`, between a floor and a ceiling.
     *
     * The floor keeps a `no-cache` document from putting a third party's server back in the
     * request path on every authorization; the ceiling keeps a client that renamed itself or
     * withdrew a callback address from being served stale for a week.
     */
    private function lifetimeFrom(string $cacheControl): int
    {
        if (1 !== preg_match('/(?:^|,)\s*max-age=(\d+)/i', $cacheControl, $matches)) {
            return self::MIN_CACHE_SECONDS;
        }

        return max(self::MIN_CACHE_SECONDS, min(self::MAX_CACHE_SECONDS, (int) $matches[1]));
    }

    /**
     * Everything that can be decided without opening a connection.
     *
     * @throws ClientMetadataRejected
     */
    private function assertAddressable(string $clientId): void
    {
        if (\strlen($clientId) > OAuthClient::IDENTIFIER_MAX_LENGTH) {
            throw $this->reject($clientId, 'Client id is longer than the column that stores it.');
        }

        $parts = parse_url($clientId);
        if (false === $parts || !\is_array($parts)) {
            throw $this->reject($clientId, 'Client id is not a URL.');
        }

        if ('https' !== ($parts['scheme'] ?? null)) {
            throw $this->reject($clientId, 'Client id must use the https scheme.');
        }

        // The specification requires a path component: a bare origin as a client id would let
        // whoever controls a host claim it wholesale.
        if ('' === ($parts['path'] ?? '') || '/' === ($parts['path'] ?? '')) {
            throw $this->reject($clientId, 'Client id must name a document, not an origin.');
        }

        if (isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw $this->reject($clientId, 'Client id must carry no fragment and no credentials.');
        }

        $host = $parts['host'] ?? '';
        if ('' === $host) {
            throw $this->reject($clientId, 'Client id has no host.');
        }

        // Our own origin is refused for two reasons at once: the server would be fetching
        // itself, and a client id equal to the canonical resource URI would satisfy the
        // audience check on its own, since `permittedFor()` deduplicates what it appends.
        $ownHost = parse_url($this->ownUri, \PHP_URL_HOST);
        if (\is_string($ownHost) && 0 === strcasecmp($host, $ownHost)) {
            throw $this->reject($clientId, 'Client id points at this server.');
        }
    }

    /**
     * @throws ClientMetadataRejected
     */
    private function parse(string $clientId, string $body): ClientMetadata
    {
        try {
            $document = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw $this->reject($clientId, 'Metadata document is not JSON: '.$jsonException->getMessage());
        }

        if (!\is_array($document)) {
            throw $this->reject($clientId, 'Metadata document is not an object.');
        }

        // The document has to name itself, byte for byte. Without this, a page on any host
        // could hand back a document claiming to be any client.
        if (($document['client_id'] ?? null) !== $clientId) {
            throw $this->reject($clientId, 'Metadata document claims a different client id.');
        }

        $name = $document['client_name'] ?? null;
        if (!\is_string($name) || '' === trim($name) || \strlen($name) > self::MAX_CLIENT_NAME_LENGTH) {
            throw $this->reject($clientId, 'Metadata document has no usable client_name.');
        }

        $uris = $document['redirect_uris'] ?? null;
        if (!\is_array($uris) || [] === $uris || \count($uris) > self::MAX_REDIRECT_URIS) {
            throw $this->reject($clientId, 'Metadata document declares no usable redirect_uris.');
        }

        $redirectUris = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || '' === $uri || !$this->isAcceptableRedirectUri($uri)) {
                throw $this->reject($clientId, 'Metadata document declares a redirect_uri we will not send a user to.');
            }

            $redirectUris[] = $uri;
        }

        $name = trim($name);
        \assert('' !== $name && '' !== $clientId);

        return new ClientMetadata($clientId, $name, $redirectUris);
    }

    /**
     * HTTPS, or a literal loopback address over plain HTTP.
     *
     * `http://localhost:3000/cb` is refused even though league would happily match it: it is
     * an ordinary DNS name, so it can be made to point elsewhere, and league's loopback rule
     * covers only the literal addresses. Leaving it in would mean the one `http://` redirect
     * we accept is the one nobody controls.
     */
    private function isAcceptableRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || !\is_array($parts) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if ('https' === $scheme) {
            return '' !== $host;
        }

        return 'http' === $scheme && \in_array($host, self::LOOPBACK_HOSTS, true);
    }

    private function reject(string $clientId, string $reason): ClientMetadataRejected
    {
        // The reason goes to the log. The exception carries none of it — not because anything
        // currently renders an exception message to a caller, but because a transport failure
        // quotes the IP it refused ("IP 10.0.0.7 is blocked for ..."), and one future handler
        // that echoes a message would turn this endpoint into a network scanner. The operator
        // has the detail here, with the client id next to it.
        $this->logger->warning('Refused an OAuth client metadata document', [
            'client_id' => $clientId,
            'reason' => $reason,
        ]);

        return new ClientMetadataRejected('The client identifier could not be resolved.');
    }
}
