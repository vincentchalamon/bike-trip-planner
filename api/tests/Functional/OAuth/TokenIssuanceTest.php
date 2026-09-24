<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Lcobucci\JWT\Token\DataSet;
use ApiPlatform\Test\Client;
use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use App\Tests\ApiTestCase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The whole flow, from an agent's link to a token it can use (ADR-079).
 *
 * What this pins that the consent test cannot: PKCE actually verifies, a code answers once,
 * and the token says which resource it was issued for.
 */
#[ResetDatabase]
final class TokenIssuanceTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string CLIENT_ID = 'https://agent.example.com/oauth/client.json';

    private const string REDIRECT_URI = 'http://127.0.0.1:33418/callback';

    private const string COOKIE = 'token-issuance-cookie';

    private const string VERIFIER = 'a-verifier-of-at-least-43-characters-for-pkce-ok';

    private const string CHALLENGE = 'lsmMqplmuEP5Qsegofd3pZlGReS7RX_Y4y8NFq6kGhQ';

    private Client $browser;

    private string $jwt;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = new User('agent-owner@example.com');
        $em->persist($user);
        $em->persist(RefreshToken::issue(
            $user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            self::COOKIE,
            new \DateTimeImmutable('+30 days'),
        ));
        $em->flush();

        $this->jwt = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);

        $client = new OAuthClient('Example Agent', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        self::getContainer()->get(ClientManagerInterface::class)->save($client);

        $this->browser = self::createClient();
        $this->browser->getCookieJar()->set(
            new BrowserKitCookie('refresh_token', self::COOKIE, null, '/', 'localhost'),
        );
    }

    #[Test]
    public function anIssuedTokenNamesTheResourceItIsFor(): void
    {
        $token = $this->exchange($this->approvedCode())->toArray(false);

        self::assertResponseIsSuccessful();
        self::assertSame('Bearer', $token['token_type']);
        self::assertArrayHasKey('refresh_token', $token);

        $claims = $this->claimsOf($token['access_token']);
        $audience = (array) $claims->get('aud');

        // The client first: BearerTokenValidator reads aud[0] as the client identifier, and
        // permittedFor() appends — so the order is not cosmetic.
        self::assertSame(self::CLIENT_ID, $audience[0]);
        self::assertContains('https://localhost/mcp', $audience);
        self::assertSame(['trips:read'], $claims->get('scopes'));
        self::assertSame('agent-owner@example.com', $claims->get('sub'));
    }

    /**
     * PKCE is what stops a stolen authorization code being usable by whoever stole it. The
     * bundle requires a challenge from public clients by default; this asserts the other
     * half — that the verifier is actually checked.
     */
    #[Test]
    public function aWrongVerifierBuysNothing(): void
    {
        $response = $this->exchange($this->approvedCode(), verifier: 'a-different-verifier-of-at-least-43-characters-x');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $response->toArray(false)['error'] ?? null);
    }

    #[Test]
    public function aCodeAnswersOnce(): void
    {
        $code = $this->approvedCode();

        $this->exchange($code);
        self::assertResponseIsSuccessful();

        $replay = $this->exchange($code);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $replay->toArray(false)['error'] ?? null);
    }

    /**
     * RFC 8707: a client names the server it means to use the token at. This one issues for
     * a single resource, so anything else is refused rather than quietly ignored.
     */
    #[Test]
    public function aTokenForAnotherResourceIsRefused(): void
    {
        $this->browser->request('GET', '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope' => 'trips:read',
            'resource' => 'https://someone-else.example/mcp',
        ]));

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * RFC 8707 makes `resource` a client obligation, not a server one. A client that omits
     * it is still served — this server has nowhere else a token could be for — and the token
     * it gets is bound to the resource all the same. Every other test here sends the
     * parameter, so without this case the accepting branch is only read, never run.
     */
    #[Test]
    public function aFlowThatNeverMentionsTheResourceStillWorks(): void
    {
        $handle = $this->handleFrom($this->authorize(withResource: false));

        $this->browser->request('POST', '/oauth/pending-authorizations/'.$handle.'/approve', [
            'headers' => ['Authorization' => 'Bearer '.$this->jwt, 'Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $query = parse_url($this->location($this->authorize(withResource: false)), \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);
        self::assertIsString($parameters['code'] ?? null);

        $token = $this->exchange($parameters['code'], withResource: false)->toArray(false);

        self::assertArrayHasKey('access_token', $token);
        // Bound to the resource anyway: the parameter says where the client INTENDS to use
        // the token, and this server has one answer to that question either way.
        self::assertContains('https://localhost/mcp', (array) $this->claimsOf($token['access_token'])->get('aud'));
    }

    private function approvedCode(): string
    {
        $handle = $this->handleFrom($this->authorize());

        $this->browser->request('POST', '/oauth/pending-authorizations/'.$handle.'/approve', [
            'headers' => ['Authorization' => 'Bearer '.$this->jwt, 'Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $query = parse_url($this->location($this->authorize()), \PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $parameters);
        self::assertArrayHasKey('code', $parameters);
        self::assertIsString($parameters['code']);

        return $parameters['code'];
    }

    private function authorize(bool $withResource = true): ResponseInterface
    {
        return $this->browser->request('GET', '/oauth/authorize?'.http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope' => 'trips:read',
            'resource' => $withResource ? 'https://localhost/mcp' : null,
        ])));
    }

    private function exchange(string $code, string $verifier = self::VERIFIER, bool $withResource = true): ResponseInterface
    {
        // Form parameters, not a raw body: league reads `getParsedBody()`, which the PSR-7
        // bridge fills from the request's POST bag — a body string alone never reaches it,
        // and the endpoint answers `unsupported_grant_type` as if no grant had been named.
        return $this->browser->request('POST', '/oauth/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'extra' => ['parameters' => array_filter([
                'grant_type' => 'authorization_code',
                'client_id' => self::CLIENT_ID,
                'redirect_uri' => self::REDIRECT_URI,
                'code_verifier' => $verifier,
                'code' => $code,
                'resource' => $withResource ? 'https://localhost/mcp' : null,
            ])],
        ]);
    }

    private function claimsOf(string $accessToken): DataSet
    {
        self::assertNotSame('', $accessToken);

        $token = new Parser(new JoseEncoder())->parse($accessToken);
        self::assertInstanceOf(UnencryptedToken::class, $token);

        return $token->claims();
    }

    private function location(ResponseInterface $response): string
    {
        return (string) ($response->getHeaders(false)['location'][0] ?? '');
    }

    private function handleFrom(ResponseInterface $response): string
    {
        $location = $this->location($response);

        return substr($location, strrpos($location, '/') + 1);
    }
}
