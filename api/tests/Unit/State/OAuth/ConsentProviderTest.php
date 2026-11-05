<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\OAuth;

use ApiPlatform\Metadata\Get;
use App\Security\OAuth\ConsentRecord;
use App\Security\OAuth\ConsentStore;
use App\State\OAuth\ConsentProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A client names itself in its metadata document; the host of its client_id is the part it
 * cannot choose. The consent screen shows both, so the host has to come through.
 */
final class ConsentProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function clientIds(): iterable
    {
        yield 'metadata document URL' => ['https://agent.example.com/oauth/client.json', 'agent.example.com'];
        yield 'opaque identifier' => ['pre-registered-client', null];
    }

    #[Test]
    #[DataProvider('clientIds')]
    public function exposesTheHostOfTheClientId(string $clientId, ?string $expectedHost): void
    {
        $store = new ConsentStore(new ArrayAdapter(), 'secret');
        $store->put('a-handle', new ConsentRecord(
            userId: 'rider@example.com',
            clientId: $clientId,
            clientName: 'Totally Official',
            redirectUri: 'https://elsewhere.example.net/callback',
            scopes: ['trips:read'],
            continueUrl: '/oauth/authorize?response_type=code',
        ));

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('rider@example.com', null));

        $consent = new ConsentProvider($store, $security)->provide(new Get(), ['handle' => 'a-handle']);

        self::assertSame($expectedHost, $consent->clientIdHost);
        self::assertSame('elsewhere.example.net', $consent->redirectHost);
    }
}
