<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\OAuth;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

/**
 * The one guard the resolver does not implement itself.
 *
 * Every rule about which URLs are opened and which documents are believed lives in
 * {@see \App\Security\OAuth\ClientMetadataResolver} and is unit-tested there. What stops a
 * hostname that resolves to the internal network is Symfony's NoPrivateNetworkHttpClient,
 * and it only stops anything if the decoration in services.php is actually in place — a
 * boolean the resolver's own tests cannot see, since they inject a mock.
 *
 * Deliberately a wiring assertion rather than a DNS one: pointing a test at an internal
 * hostname would fail whether the guard exists or not (nothing answers TLS there), so it
 * would pass for the wrong reason and keep passing after the decoration was removed.
 */
final class ClientMetadataClientWiringTest extends KernelTestCase
{
    #[Test]
    public function theClientMetadataFetcherRefusesPrivateNetworks(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            NoPrivateNetworkHttpClient::class,
            self::getContainer()->get('oauth_client_metadata.client'),
            'The OAuth client metadata fetcher is the only HTTP client here whose host is chosen '
            .'by a third party, and it is no longer wrapped by the private-network guard.',
        );
    }
}
