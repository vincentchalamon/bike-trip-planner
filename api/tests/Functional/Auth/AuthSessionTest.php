<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `GET /auth/session` — read-only session introspection (recette #649 #8, ADR-047).
 *
 * The load-bearing distinction from `/auth/refresh` is that this endpoint must
 * NEVER rotate: it returns `{authenticated}` for the current cookie without a
 * Set-Cookie and without touching the token's `replaced_by_token`.
 */
#[ResetDatabase]
final class AuthSessionTest extends ApiTestCase
{
    use Factories;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function createUserWithRefreshToken(
        string $email = 'test@example.com',
        string $token = 'valid-refresh-token',
        ?\DateTimeImmutable $expiresAt = null,
    ): User {
        $em = $this->getEntityManager();
        $user = new User($email);
        $em->persist($user);

        $refreshToken = new RefreshToken(
            $user,
            $token,
            $expiresAt ?? new \DateTimeImmutable('+30 days'),
        );
        $em->persist($refreshToken);
        $em->flush();

        return $user;
    }

    private function getSession(?string $cookieToken = null): ResponseInterface
    {
        $headers = ['Accept' => 'application/ld+json'];
        if (null !== $cookieToken) {
            $headers['Cookie'] = 'refresh_token='.$cookieToken;
        }

        return self::createClient()->request('GET', '/auth/session', ['headers' => $headers]);
    }

    #[Test]
    public function validCookieReportsAuthenticatedUser(): void
    {
        $user = $this->createUserWithRefreshToken('alice@example.com', 'alice-session-token');

        $response = $this->getSession('alice-session-token');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertTrue($data['authenticated']);
        $this->assertSame($user->getId()->toRfc4122(), $data['userId']);
        $this->assertSame('alice@example.com', $data['email']);
    }

    #[Test]
    public function missingCookieReportsUnauthenticatedWithout401(): void
    {
        $response = $this->getSession();

        // The key difference from /auth/refresh: anonymous is a normal 200
        // {authenticated:false}, not a 401 — the RSC gate calls this on every load.
        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($response->toArray(false)['authenticated']);
    }

    #[Test]
    public function unknownTokenReportsUnauthenticated(): void
    {
        $response = $this->getSession('nonexistent-token-xyz');

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($response->toArray(false)['authenticated']);
    }

    #[Test]
    public function expiredTokenReportsUnauthenticated(): void
    {
        $this->createUserWithRefreshToken(
            'expired@example.com',
            'expired-session-token',
            new \DateTimeImmutable('-1 day'),
        );

        $response = $this->getSession('expired-session-token');

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($response->toArray(false)['authenticated']);
    }

    #[Test]
    public function deletedAccountReportsUnauthenticated(): void
    {
        $user = $this->createUserWithRefreshToken('deleted@example.com', 'deleted-session-token');
        $em = $this->getEntityManager();
        $user->anonymize();
        $em->flush();

        $response = $this->getSession('deleted-session-token');

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($response->toArray(false)['authenticated']);
    }

    #[Test]
    public function doesNotRotateOrSetCookie(): void
    {
        $this->createUserWithRefreshToken('idempotent@example.com', 'idempotent-token');

        $response = $this->getSession('idempotent-token');

        $this->assertResponseStatusCodeSame(200);
        // Introspection is idempotent: no Set-Cookie (inverse of /auth/refresh).
        $this->assertArrayNotHasKey('set-cookie', $response->getHeaders(false));

        // ...and the token is NOT rotated (its successor pointer stays NULL).
        $em = $this->getEntityManager();
        $em->clear();
        $token = $em->getRepository(RefreshToken::class)->findOneBy(['token' => 'idempotent-token']);
        $this->assertNotNull($token);
        $this->assertNull($token->getReplacedByToken(), 'session must not rotate the token');
    }
}
