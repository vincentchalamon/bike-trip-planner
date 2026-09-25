<?php

declare(strict_types=1);

namespace App\Tests\Integration\HttpClient;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The route clients as the container builds them, down to the URL that leaves the process.
 *
 * Every fetcher calls its client with a relative path (`/tour/{id}`) and relies on the scoped
 * client's `base_uri`. Since #1229 the scoped clients are wrapped in NoPrivateNetworkHttpClient,
 * which resolves a URL against its OWN options before delegating — and it had no base_uri, so
 * every fetch died with "scheme is missing" before reaching the network. Found by the manual
 * Inspector pass, invisible to the suite because every fetcher test doubles its client.
 *
 * So this goes through the real service graph and swaps only the transport at the bottom of it.
 * `resolve` pins the host to a public address, so the private-network guard runs its check
 * without a DNS lookup.
 */
final class RouteSourceClientTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function clients(): iterable
    {
        yield 'komoot' => ['komoot.client', 'www.komoot.com', '/tour/2795080048', 'https://www.komoot.com/tour/2795080048'];
        yield 'strava' => ['strava.client', 'www.strava.com', '/routes/123/export_gpx', 'https://www.strava.com/routes/123/export_gpx'];
        yield 'ridewithgps' => ['ridewithgps.client', 'ridewithgps.com', '/routes/456.json', 'https://ridewithgps.com/routes/456.json'];
    }

    #[Test]
    #[DataProvider('clients')]
    public function aRelativePathReachesTheThirdPartyWithTheScopedOptions(string $client, string $host, string $path, string $expected): void
    {
        $sent = [];
        $this->transport(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('ok');
        });

        $response = $this->client($client)->request('GET', $path, ['resolve' => [$host => '93.184.216.34']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expected, $sent['url'] ?? null);
        self::assertContains('User-Agent: BikeTripPlanner/1.0', $sent['headers'] ?? [], "The scoped client's options must still apply behind the guard.");
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function guardedClients(): iterable
    {
        foreach (self::clients() as $name => [$client, $host, $path]) {
            yield $name => [$client, $host, $path];
        }
    }

    /** The guard is still there: a private address is refused before anything is sent. */
    #[Test]
    #[DataProvider('guardedClients')]
    public function aPrivateAddressIsStillRefused(string $client, string $host, string $path): void
    {
        $this->transport(static fn (): MockResponse => new MockResponse('should not be reached'));

        $this->expectException(TransportException::class);

        $this->client($client)->request('GET', $path, ['resolve' => [$host => '10.0.0.5']])->getStatusCode();
    }

    private function transport(callable $factory): void
    {
        self::bootKernel();
        self::getContainer()->set('http_client.transport', new MockHttpClient($factory));
    }

    private function client(string $id): HttpClientInterface
    {
        $client = self::getContainer()->get($id);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        return $client;
    }
}
