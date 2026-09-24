<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use ApiPlatform\Test\Client;
use App\Entity\OAuthClient;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

/**
 * Mints an access token the way an agent does: by walking the flow.
 *
 * Not by reaching into the token repository. The private signing key is an inline definition
 * rather than a service, so a shortcut would have to rebuild the issuing path — and a token
 * assembled by the test rather than by the server proves nothing about the server. Six
 * requests is a fair price for a credential that is genuinely one of ours.
 */
trait IssuesOAuthTokensTrait
{
    private const string OAUTH_CLIENT_ID = 'https://agent.example.com/oauth/client.json';

    private const string OAUTH_REDIRECT_URI = 'http://127.0.0.1:33418/callback';

    private const string OAUTH_VERIFIER = 'a-verifier-of-at-least-43-characters-for-pkce-ok';

    private const string OAUTH_CHALLENGE = 'lsmMqplmuEP5Qsegofd3pZlGReS7RX_Y4y8NFq6kGhQ';

    /**
     * @param list<string> $scopes
     */
    private function issueAccessTokenFor(User $user, array $scopes = ['trips:read']): string
    {
        $cookie = bin2hex(random_bytes(16));

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(RefreshToken::issue(
            $user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            $cookie,
            new \DateTimeImmutable('+30 days'),
        ));
        $em->flush();

        $sessionJwt = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($user);

        $clients = self::getContainer()->get(ClientManagerInterface::class);
        if (null === $clients->find(self::OAUTH_CLIENT_ID)) {
            $client = new OAuthClient('Example Agent', self::OAUTH_CLIENT_ID, null);
            $client->setRedirectUris(new RedirectUri(self::OAUTH_REDIRECT_URI));
            $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
            $client->setScopes(...array_map(static fn (string $s): Scope => new Scope($s), ['trips:read', 'trips:write']));
            $clients->save($client);
        }

        $browser = self::createClient();
        $browser->getCookieJar()->set(new BrowserKitCookie('refresh_token', $cookie, null, '/', 'localhost'));

        $handle = $this->pendingHandle($browser, $scopes);

        $browser->request('POST', '/oauth/pending-authorizations/'.$handle.'/approve', [
            'headers' => ['Authorization' => 'Bearer '.$sessionJwt, 'Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $query = parse_url($this->redirectLocation($browser, $scopes), \PHP_URL_QUERY);
        parse_str(\is_string($query) ? $query : '', $parameters);

        $token = $browser->request('POST', '/oauth/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'extra' => ['parameters' => [
                'grant_type' => 'authorization_code',
                'client_id' => self::OAUTH_CLIENT_ID,
                'redirect_uri' => self::OAUTH_REDIRECT_URI,
                'code_verifier' => self::OAUTH_VERIFIER,
                'code' => \is_string($parameters['code'] ?? null) ? $parameters['code'] : '',
                'resource' => 'https://localhost/mcp',
            ]],
        ])->toArray(false);

        self::assertArrayHasKey('access_token', $token, 'The flow did not issue a token.');

        return (string) $token['access_token'];
    }

    /**
     * @param list<string> $scopes
     */
    private function pendingHandle(Client $browser, array $scopes): string
    {
        $location = $this->redirectLocation($browser, $scopes);

        return substr($location, strrpos($location, '/') + 1);
    }

    /**
     * @param list<string> $scopes
     */
    private function redirectLocation(Client $browser, array $scopes): string
    {
        $response = $browser->request('GET', '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::OAUTH_CLIENT_ID,
            'redirect_uri' => self::OAUTH_REDIRECT_URI,
            'code_challenge' => self::OAUTH_CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope' => implode(' ', $scopes),
            'resource' => 'https://localhost/mcp',
        ]));

        return (string) ($response->getHeaders(false)['location'][0] ?? '');
    }
}
