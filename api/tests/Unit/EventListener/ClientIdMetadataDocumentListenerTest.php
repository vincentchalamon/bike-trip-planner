<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\OAuthClient;
use App\Entity\User;
use App\EventListener\ClientIdMetadataDocumentListener;
use App\Security\OAuth\ClientMetadataResolver;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * When the authorization server is willing to open a connection on a caller's say-so.
 *
 * The assertions here are about the REQUEST COUNT, not about whether a collaborator was
 * called: what matters is that no packet leaves, and a mock HTTP client is the only witness
 * that can say so.
 */
#[AllowMockObjectsWithoutExpectations]
final class ClientIdMetadataDocumentListenerTest extends TestCase
{
    private const string CLIENT_ID = 'https://agent.example.com/client.json';

    private MockObject&ClientManagerInterface $clients;

    private MockObject&Security $security;

    #[\Override]
    protected function setUp(): void
    {
        $this->clients = $this->createMock(ClientManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->security->method('getUser')->willReturn(new User('owner@example.com'));
    }

    /**
     * THE assertion of this listener.
     *
     * `/oauth/token` is PUBLIC_ACCESS — it authenticates the client with a code and a PKCE
     * verifier, not the user, so no firewall stands in front of it. Resolving a metadata
     * document there would put an outbound request to a caller-named host behind no
     * authentication at all. It never needs to: the authorization leg already persisted the
     * client, and the token endpoint finds it in the database.
     */
    #[Test]
    public function theTokenEndpointNeverFetchesAnything(): void
    {
        $http = new MockHttpClient([]);
        $event = $this->event('oauth2_token');

        $this->listener($http)($event);

        self::assertSame(0, $http->getRequestsCount());
        self::assertFalse($event->hasResponse());
    }

    #[Test]
    public function aClientIdThatIsNotAUrlIsLeftAlone(): void
    {
        $http = new MockHttpClient([]);

        $this->listener($http)($this->event('oauth2_authorize', 'a-pre-registered-client'));

        self::assertSame(0, $http->getRequestsCount());
    }

    /**
     * A client already on file is not re-read on every authorization: that would hand a third
     * party one request per attempt. The resolver's cache expiry is what brings changes back.
     */
    #[Test]
    public function aKnownClientIsNotFetchedAgain(): void
    {
        $http = new MockHttpClient([]);
        $this->clients->method('find')->willReturn(new OAuthClient('Known', self::CLIENT_ID, null));

        $this->listener($http)($this->event('oauth2_authorize'));

        self::assertSame(0, $http->getRequestsCount());
    }

    #[Test]
    public function anUnknownClientIsResolvedAndPersisted(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Example Agent',
            'redirect_uris' => ['http://127.0.0.1:3000/cb'],
        ], \JSON_THROW_ON_ERROR)));

        $this->clients->method('find')->willReturn(null);
        $saved = null;
        $this->clients->expects(self::once())->method('save')
            ->willReturnCallback(static function (OAuthClient $client) use (&$saved): void {
                $saved = $client;
            });

        $event = $this->event('oauth2_authorize');
        $this->listener($http)($event);

        self::assertSame(1, $http->getRequestsCount());
        self::assertFalse($event->hasResponse());
        self::assertInstanceOf(OAuthClient::class, $saved);
        self::assertSame('Example Agent', $saved->getName());
        self::assertNull($saved->getSecret(), 'A client registered by document is public.');
        self::assertFalse($saved->isPlainTextPkceAllowed());
    }

    #[Test]
    public function aRefusedDocumentStopsTheRequestWithoutSayingWhy(): void
    {
        $http = new MockHttpClient(new MockResponse('{"client_id":"https://elsewhere.example/c.json"}'));
        $this->clients->method('find')->willReturn(null);
        $this->clients->expects(self::never())->method('save');

        $event = $this->event('oauth2_authorize');
        $this->listener($http)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $body = (string) $response->getContent();
        self::assertStringContainsString('invalid_client', $body);
        self::assertStringNotContainsString('elsewhere.example', $body);
    }

    /**
     * The budget is spent before the connection is opened, so an account cannot make this
     * server hammer a third party — nor fill `oauth2_client` one distinct URL at a time.
     */
    #[Test]
    public function aUserPastItsBudgetStopsBeforeTheFetch(): void
    {
        $http = new MockHttpClient([]);
        $this->clients->method('find')->willReturn(null);

        $spent = $this->limiter('per_user', 1);
        $spent->create('owner@example.com')->consume();

        $listener = new ClientIdMetadataDocumentListener(
            new ClientMetadataResolver($http, new ArrayAdapter(), new NullLogger(), 'https://bike-trip-planner.test'),
            $this->clients,
            $this->security,
            $spent,
            $this->limiter('per_host', 10),
        );

        $this->expectException(TooManyRequestsHttpException::class);

        try {
            $listener($this->event('oauth2_authorize'));
        } finally {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    private function event(string $route, string $clientId = self::CLIENT_ID): RequestEvent
    {
        $request = Request::create('/oauth/authorize?'.http_build_query(['client_id' => $clientId]));
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function listener(MockHttpClient $http): ClientIdMetadataDocumentListener
    {
        return new ClientIdMetadataDocumentListener(
            new ClientMetadataResolver($http, new ArrayAdapter(), new NullLogger(), 'https://bike-trip-planner.test'),
            $this->clients,
            $this->security,
            $this->limiter('per_user', 10),
            $this->limiter('per_host', 10),
        );
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }
}
