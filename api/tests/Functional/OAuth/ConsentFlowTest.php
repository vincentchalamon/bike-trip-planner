<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use Symfony\Contracts\HttpClient\ResponseInterface;
use ApiPlatform\Test\Client;
use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use App\Tests\ApiTestCase;
use App\Tests\Functional\JwtAuthTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The consent round trip, end to end on the server side (ADR-079).
 *
 * The browser crosses the endpoint twice: once to be asked, once to complete. Nothing is
 * added to the URL in between — the handle is derived from the request, so the second leg
 * recomputes it. What is pinned here is that the decision is required, that it belongs to
 * one user and one request, and that it answers exactly once.
 */
#[ResetDatabase]
final class ConsentFlowTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string CLIENT_ID = 'https://agent.example.com/oauth/client.json';

    private const string REDIRECT_URI = 'http://127.0.0.1:33418/callback';

    // A SECOND address the client legitimately registered. Needed to prove the handle binds
    // the redirect_uri: swapping to an unregistered one would be refused by league first, so
    // the test would pass without the binding existing at all.
    private const string OTHER_REDIRECT_URI = 'https://agent.example.com/callback';

    private const string COOKIE = 'consent-flow-cookie';

    // S256 of 'a-verifier-of-at-least-43-characters-for-pkce-ok'.
    private const string CODE_CHALLENGE = 'lsmMqplmuEP5Qsegofd3pZlGReS7RX_Y4y8NFq6kGhQ';

    private User $user;

    private string $jwt;

    private Client $browser;

    #[\Override]
    protected function setUp(): void
    {
        // #[ResetDatabase] resets Postgres, not Redis — and the handle is derived from the
        // request, so every test here computes the same one. Without this, a decision taken
        // by one test completes the first leg of the next.
        self::getContainer()->get('cache.oauth_consent')->clear();

        $em = $this->entityManager();

        $this->user = new User('agent-owner@example.com');
        $em->persist($this->user);
        $em->persist(RefreshToken::issue(
            $this->user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            self::COOKIE,
            new \DateTimeImmutable('+30 days'),
        ));
        $em->flush();

        $this->jwt = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user);

        $oauthClient = new OAuthClient('Example Agent', self::CLIENT_ID, null);
        $oauthClient->setRedirectUris(new RedirectUri(self::REDIRECT_URI), new RedirectUri(self::OTHER_REDIRECT_URI));
        $oauthClient->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        self::getContainer()->get(ClientManagerInterface::class)->save($oauthClient);

        // One client for the whole test: the browser carries the cookie across both legs of
        // the flow, which is what a real one does.
        $this->browser = self::createClient();
        $this->browser->getCookieJar()->set(
            new BrowserKitCookie('refresh_token', self::COOKIE, null, '/', 'localhost'),
        );
    }

    #[Test]
    public function theFirstPassSendsTheBrowserToTheConsentScreen(): void
    {
        $response = $this->authorize();

        self::assertResponseStatusCodeSame(302);
        // No `iss`, and the exact shape says why: this redirect goes to our own consent
        // screen, not back to the client, so it is not an authorization response.
        self::assertMatchesRegularExpression(
            '#^https://localhost/oauth/consent/[0-9a-f]{64}$#',
            $this->location($response),
        );
    }

    /**
     * league resolves an empty `scope` against the client's own list, but only on the way to
     * issuing the token — after this screen, which would have shown nothing at all. Rather
     * than display an empty list and hand out permissions afterwards, the request is refused.
     */
    #[Test]
    public function anAuthorizationThatNamesNoScopeIsRefused(): void
    {
        $response = $this->authorize(scope: null);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_scope', $response->toArray(false)['error'] ?? null);
    }

    #[Test]
    public function theScreenIsToldWhatTheServerValidated(): void
    {
        $handle = $this->handleFrom($this->authorize());

        $consent = $this->read($handle, $this->jwt)->toArray(false);

        self::assertSame('Example Agent', $consent['clientName']);
        self::assertSame(['trips:read'], $consent['scopes']);
        self::assertSame('127.0.0.1', $consent['redirectHost']);
        self::assertTrue($consent['redirectsToLoopback']);
        self::assertStringStartsWith('/oauth/authorize?', $consent['continueUrl']);
    }

    #[Test]
    public function aPendingConsentBelongsToOneUser(): void
    {
        $handle = $this->handleFrom($this->authorize());

        ['token' => $otherJwt] = $this->createTestUserWithJwt('someone-else@example.com');
        $this->read($handle, $otherJwt);

        // The same answer as a handle that does not exist: telling them apart would confirm
        // that this authorization is in flight.
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function approvingLetsTheSecondPassIssueACode(): void
    {
        $handle = $this->handleFrom($this->authorize());
        $this->decide($handle, 'approve');
        self::assertResponseStatusCodeSame(204);

        $response = $this->authorize();

        self::assertResponseStatusCodeSame(302);
        $location = $this->location($response);
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        self::assertStringContainsString('code=', $location);
        self::assertStringContainsString('state=opaque-state', $location);
        // RFC 9207: a client talking to more than one authorization server cannot otherwise
        // tell which one answered, which is what a mix-up attack exploits. league emits none.
        self::assertStringContainsString('iss=https%3A%2F%2Flocalhost', $location);
    }

    #[Test]
    public function refusingSendsTheAgentAwayEmptyHanded(): void
    {
        $handle = $this->handleFrom($this->authorize());
        $this->decide($handle, 'deny');

        $response = $this->authorize();

        self::assertResponseStatusCodeSame(302);
        $location = $this->location($response);
        self::assertStringContainsString('error=access_denied', $location);
        // Error responses carry it too: a client must be able to tell who refused.
        self::assertStringContainsString('iss=https%3A%2F%2Flocalhost', $location);
    }

    /**
     * A decision answers one authorization request. Replaying the return leg must land back
     * on the consent screen rather than mint a second code.
     */
    #[Test]
    public function aDecisionIsSpentWhenItIsUsed(): void
    {
        $handle = $this->handleFrom($this->authorize());
        $this->decide($handle, 'approve');

        $this->authorize();
        $replay = $this->authorize();

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('https://localhost/oauth/consent/', $this->location($replay));
    }

    /**
     * The handle is derived from the request, so a decision taken for one set of arguments
     * cannot complete another. Each field of that derivation gets its own case, because a
     * refactor that drops one would leave the others passing.
     *
     * `redirect_uri` is the sharp one: swapping it after consent is the classic
     * code-exfiltration move, and league would not catch a swap between two addresses the
     * client legitimately registered. `code_challenge` is what binds PKCE to this attempt.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function tamperedSecondLegs(): iterable
    {
        yield 'a wider scope' => [['scope' => 'trips:read trips:write']];
        yield 'another registered redirect_uri' => [['redirect_uri' => self::OTHER_REDIRECT_URI]];
        // S256 of a different verifier.
        yield 'another code challenge' => [['code_challenge' => 'ZmFrZS1jaGFsbGVuZ2UtZm9yLWEtZGlmZmVyZW50LXZlcmlmaWU']];
    }

    /**
     * @param array<string, string> $changed
     */
    #[Test]
    #[DataProvider('tamperedSecondLegs')]
    public function aDecisionDoesNotTravelToADifferentRequest(array $changed): void
    {
        $handle = $this->handleFrom($this->authorize());
        $this->decide($handle, 'approve');

        $second = $this->authorize(...$changed);

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('https://localhost/oauth/consent/', $this->location($second));
    }

    private function authorize(
        ?string $scope = 'trips:read',
        string $redirect_uri = self::REDIRECT_URI,
        string $code_challenge = self::CODE_CHALLENGE,
    ): ResponseInterface {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirect_uri,
            'state' => 'opaque-state',
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
        ]);

        return $this->browser->request('GET', '/oauth/authorize?'.http_build_query($query));
    }

    /**
     * `getHeaders()` throws on a 3xx unless told not to, and every response here is one.
     */
    private function location(ResponseInterface $response): string
    {
        return (string) ($response->getHeaders(false)['location'][0] ?? '');
    }

    private function handleFrom(ResponseInterface $response): string
    {
        $location = $this->location($response);

        return substr($location, strrpos($location, '/') + 1);
    }

    private function read(string $handle, string $jwt): ResponseInterface
    {
        return $this->browser->request('GET', '/oauth/pending-authorizations/'.$handle, [
            'headers' => ['Authorization' => 'Bearer '.$jwt, 'Accept' => 'application/ld+json'],
        ]);
    }

    private function decide(string $handle, string $decision): void
    {
        $this->browser->request('POST', '/oauth/pending-authorizations/'.$handle.'/'.$decision, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->jwt,
                'Content-Type' => 'application/ld+json',
            ],
            'json' => new \stdClass(),
        ]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
