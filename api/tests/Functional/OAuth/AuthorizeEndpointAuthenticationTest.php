<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Who `/oauth/authorize` accepts, and what it does with everyone else (ADR-079).
 *
 * The endpoint is the one place a browser — not a client library — has to prove who it is,
 * and the only browser credential this deployment has is the BFF's httpOnly refresh cookie.
 * Two properties are pinned here: that cookie opens the authorization endpoint, and it opens
 * nothing else.
 */
#[ResetDatabase]
final class AuthorizeEndpointAuthenticationTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    #[Test]
    public function noCookieIsSentToTheLoginPage(): void
    {
        $this->authorize();

        // Not a JSON 401: the caller is a person following an agent's link, and a JSON body
        // renders as a blank page.
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://localhost/login');
    }

    #[Test]
    public function anUnknownCookieIsSentToTheLoginPage(): void
    {
        $this->authorize('not-a-token-anyone-issued');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://localhost/login');
    }

    #[Test]
    public function anExpiredCookieIsSentToTheLoginPage(): void
    {
        $this->seedUser('expired@example.com', 'expired-token', new \DateTimeImmutable('-1 day'));

        $this->authorize('expired-token');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://localhost/login');
    }

    /**
     * GDPR erasure is final, and it must hold on this endpoint too: the cookie of an
     * anonymised account is still a valid, unexpired row.
     */
    #[Test]
    public function aDeletedAccountIsSentToTheLoginPage(): void
    {
        $user = $this->seedUser('deleted@example.com', 'deleted-token');
        $user->anonymize();
        $this->entityManager()->flush();

        $this->authorize('deleted-token');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://localhost/login');
    }

    /**
     * A valid cookie gets past the firewall, so the request reaches league — which then
     * rejects it on its own terms, for the reason it should: no client_id. Anything other
     * than an OAuth error here (a redirect to login, a 401, a 500) would mean the
     * authentication leg did not do its job.
     */
    #[Test]
    public function aValidCookieReachesTheAuthorizationServer(): void
    {
        $this->seedUser('owner@example.com', 'owner-token');

        $response = $this->authorize('owner-token');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_request', $response->toArray(false)['error'] ?? null);
    }

    /**
     * The refresh cookie must not become a general-purpose API credential. It is mounted on
     * one firewall, matching one path; everywhere else the API still wants a Bearer.
     */
    #[Test]
    public function theCookieOpensNothingElse(): void
    {
        $this->seedUser('owner@example.com', 'owner-token');

        $client = self::createClient();
        $client->getCookieJar()->set(new BrowserKitCookie('refresh_token', 'owner-token', null, '/', 'localhost'));
        $client->request('GET', '/trips', ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(401);
    }

    private function authorize(?string $cookieToken = null): ResponseInterface
    {
        $client = self::createClient();

        if (null !== $cookieToken) {
            // A raw `Cookie` header never populates $request->cookies; the token has to go
            // through the BrowserKit CookieJar (see AuthSessionTest).
            $client->getCookieJar()->set(
                new BrowserKitCookie('refresh_token', $cookieToken, null, '/', 'localhost'),
            );
        }

        return $client->request('GET', '/oauth/authorize');
    }

    /**
     * @param non-empty-string $email
     */
    private function seedUser(string $email, string $token, ?\DateTimeImmutable $expiresAt = null): User
    {
        $em = $this->entityManager();

        $user = new User($email);
        $em->persist($user);
        $em->persist(RefreshToken::issue(
            $user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            $token,
            $expiresAt ?? new \DateTimeImmutable('+30 days'),
        ));
        $em->flush();

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
