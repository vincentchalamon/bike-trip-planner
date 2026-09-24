<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Security\OAuth\ClientMetadataRejected;
use App\Security\OAuth\ClientMetadataResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the authorization server is willing to fetch, and what it is willing to believe.
 *
 * A client id that is a URL is the only third-party-chosen address this application ever
 * opens. The network guard itself is Symfony's NoPrivateNetworkHttpClient, wired in
 * services.php and exercised by {@see \App\Tests\Integration\Security\OAuth\ClientMetadataClientWiringTest};
 * what is pinned here is everything decided around it — which URLs are never opened at all,
 * which documents are never believed, and that a failed fetch says nothing about why.
 */
final class ClientMetadataResolverTest extends TestCase
{
    private const string OWN_URI = 'https://bike-trip-planner.test';

    /**
     * @return iterable<string, array{string}>
     */
    public static function unopenableUrls(): iterable
    {
        yield 'plain http' => ['http://app.example.com/client.json'];
        yield 'no path' => ['https://app.example.com'];
        yield 'root path only' => ['https://app.example.com/'];
        yield 'carries a fragment' => ['https://app.example.com/client.json#x'];
        yield 'carries credentials' => ['https://user:pass@app.example.com/client.json'];
        yield 'our own host' => [self::OWN_URI.'/client.json'];
        yield 'our own host, other case' => ['https://BIKE-TRIP-PLANNER.test/client.json'];
        yield 'longer than the column' => ['https://app.example.com/'.str_repeat('a', 512).'.json'];
    }

    /**
     * None of these reach the network: the client would record a request if one were made,
     * and the assertion is that it recorded none.
     */
    #[Test]
    #[DataProvider('unopenableUrls')]
    public function someUrlsAreNeverOpenedAtAll(string $clientId): void
    {
        $http = new MockHttpClient([]);

        $this->expectException(ClientMetadataRejected::class);

        try {
            $this->resolver($http)->resolve($clientId);
        } finally {
            self::assertSame(0, $http->getRequestsCount(), 'The URL was fetched despite being refused.');
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function unbelievableDocuments(): iterable
    {
        $valid = [
            'client_id' => 'https://app.example.com/client.json',
            'client_name' => 'Example Agent',
            'redirect_uris' => ['https://app.example.com/cb'],
        ];

        yield 'not JSON' => ['<html>nope</html>'];
        yield 'not an object' => ['"a string"'];
        yield 'claims another client id' => [[...$valid, 'client_id' => 'https://elsewhere.example/client.json']];
        yield 'omits its client id' => [array_diff_key($valid, ['client_id' => null])];
        yield 'has no name' => [[...$valid, 'client_name' => '   ']];
        yield 'has a name that is not a string' => [[...$valid, 'client_name' => 42]];
        yield 'declares no redirect uri' => [[...$valid, 'redirect_uris' => []]];
        yield 'declares too many redirect uris' => [[...$valid, 'redirect_uris' => array_fill(0, 11, 'https://app.example.com/cb')]];
        yield 'declares a non-string redirect uri' => [[...$valid, 'redirect_uris' => [42]]];
        // The one that matters: league would exact-match this and send a user to it, but
        // `localhost` resolves through DNS, so nothing proves who answers.
        yield 'declares http://localhost' => [[...$valid, 'redirect_uris' => ['http://localhost:3000/cb']]];
        yield 'declares a plain http host' => [[...$valid, 'redirect_uris' => ['http://app.example.com/cb']]];
        yield 'declares a redirect uri with a fragment' => [[...$valid, 'redirect_uris' => ['https://app.example.com/cb#x']]];
    }

    /**
     * @param array<string, mixed>|string $document
     */
    #[Test]
    #[DataProvider('unbelievableDocuments')]
    public function someDocumentsAreNeverBelieved(array|string $document): void
    {
        $body = \is_string($document) ? $document : json_encode($document, \JSON_THROW_ON_ERROR);

        $this->expectException(ClientMetadataRejected::class);
        $this->resolver(new MockHttpClient(new MockResponse($body)))
            ->resolve('https://app.example.com/client.json');
    }

    #[Test]
    public function aDocumentThatNamesItselfIsAccepted(): void
    {
        $metadata = $this->resolver(new MockHttpClient(new MockResponse($this->validDocument())))
            ->resolve('https://app.example.com/client.json');

        self::assertSame('https://app.example.com/client.json', $metadata->clientId);
        self::assertSame('Example Agent', $metadata->clientName);
        // A literal loopback address is the one plain-http callback RFC 8252 allows, and the
        // one nobody else can claim.
        self::assertSame(['https://app.example.com/cb', 'http://127.0.0.1:3000/cb'], $metadata->redirectUris);
    }

    #[Test]
    public function anErrorStatusIsRefused(): void
    {
        $this->expectException(ClientMetadataRejected::class);
        $this->resolver(new MockHttpClient(new MockResponse($this->validDocument(), ['http_code' => 404])))
            ->resolve('https://app.example.com/client.json');
    }

    /**
     * NoPrivateNetworkHttpClient refuses by throwing from inside the transfer, and so does a
     * timeout or a dead host. All of it has to arrive as a refusal, never as a 500 — and
     * never with the reason attached, which would make this endpoint a network scanner.
     */
    #[Test]
    public function aFailedFetchIsARefusalAndSaysNothing(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('IP "10.0.0.7" is blocked for "https://app.example.com/client.json".');
        });

        try {
            $this->resolver($http)->resolve('https://app.example.com/client.json');
            self::fail('Expected the fetch failure to be refused.');
        } catch (ClientMetadataRejected $clientMetadataRejected) {
            self::assertStringNotContainsString('10.0.0.7', $clientMetadataRejected->getMessage());
        }
    }

    /**
     * A hostile server must not be able to make a worker read for as long as it likes. The
     * ceiling is enforced on the transfer rather than on the body afterwards, and it does not
     * trust `Content-Length`, which is a claim.
     */
    #[Test]
    public function anOversizedDocumentIsRefused(): void
    {
        $oversized = json_encode([
            'client_id' => 'https://app.example.com/client.json',
            'client_name' => 'Example Agent',
            'redirect_uris' => ['https://app.example.com/cb'],
            'padding' => str_repeat('a', 128 * 1024),
        ], \JSON_THROW_ON_ERROR);

        $this->expectException(ClientMetadataRejected::class);
        $this->resolver(new MockHttpClient(new MockResponse($oversized)))
            ->resolve('https://app.example.com/client.json');
    }

    /**
     * A second authorization must not mean a second request to someone else's server.
     */
    #[Test]
    public function aResolvedDocumentIsRemembered(): void
    {
        $http = new MockHttpClient(new MockResponse($this->validDocument()));
        $resolver = $this->resolver($http);

        $resolver->resolve('https://app.example.com/client.json');
        $resolver->resolve('https://app.example.com/client.json');

        self::assertSame(1, $http->getRequestsCount());
    }

    private function validDocument(): string
    {
        return json_encode([
            'client_id' => 'https://app.example.com/client.json',
            'client_name' => 'Example Agent',
            'redirect_uris' => ['https://app.example.com/cb', 'http://127.0.0.1:3000/cb'],
            // Read by nothing: rendering it would make the consent screen load an image from
            // an address the client chose.
            'logo_uri' => 'https://app.example.com/logo.png',
        ], \JSON_THROW_ON_ERROR);
    }

    private function resolver(MockHttpClient $http): ClientMetadataResolver
    {
        return new ClientMetadataResolver($http, new ArrayAdapter(), new NullLogger(), self::OWN_URI);
    }
}
