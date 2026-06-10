<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Contracts\HttpClient\ResponseInterface;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuthRefreshTest extends ApiTestCase
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

    /**
     * Sends a refresh request using Capacitor body transport (Origin header
     * triggers body-based refresh token reading in the processor).
     */
    private function sendRefreshRequest(string $refreshToken): ResponseInterface
    {
        return self::createClient()->request('POST', '/auth/refresh', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Origin' => 'capacitor://localhost',
            ],
            'json' => ['refresh_token' => $refreshToken],
        ]);
    }

    #[Test]
    public function refreshWithValidTokenReturnsNewJwt(): void
    {
        $this->createUserWithRefreshToken('alice@example.com', 'alice-refresh-token');

        $response = $this->sendRefreshRequest('alice-refresh-token');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    #[Test]
    public function refreshReturnsNewRefreshTokenInBody(): void
    {
        $this->createUserWithRefreshToken('rotate@example.com', 'old-refresh-token');

        $response = $this->sendRefreshRequest('old-refresh-token');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotEquals('old-refresh-token', $data['refresh_token']);
    }

    #[Test]
    public function refreshWithInvalidTokenReturns401(): void
    {
        $this->sendRefreshRequest('nonexistent-token-xyz');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function refreshWithExpiredTokenReturns401(): void
    {
        $this->createUserWithRefreshToken(
            'expired@example.com',
            'expired-refresh-token',
            new \DateTimeImmutable('-1 day'),
        );

        $this->sendRefreshRequest('expired-refresh-token');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function refreshWithMissingTokenReturns401(): void
    {
        self::createClient()->request('POST', '/auth/refresh', [
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function refreshWithinGraceWindowReturnsSameSuccessor(): void
    {
        // A rapid reload re-sends the pre-rotation token. Within the grace window
        // it must return the SAME successor (idempotent) and keep the session,
        // instead of the 401 that previously logged the user out (recette #649).
        $this->createUserWithRefreshToken('reuse@example.com', 'single-use-token');

        $first = $this->sendRefreshRequest('single-use-token');
        $this->assertResponseStatusCodeSame(200);
        $successor = $first->toArray(false)['refresh_token'];

        $second = $this->sendRefreshRequest('single-use-token');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($successor, $second->toArray(false)['refresh_token']);
    }

    #[Test]
    public function refreshCutsRotatedTokenToGraceWindow(): void
    {
        // The old token is kept (so a reload race resolves to its successor) but
        // its lifetime is cut to the grace window — it must not survive the full
        // 30-day TTL, which would widen the replay surface.
        $this->createUserWithRefreshToken('grace@example.com', 'rotated-token');

        $this->sendRefreshRequest('rotated-token');
        $this->assertResponseStatusCodeSame(200);

        $em = $this->getEntityManager();
        $em->clear();

        $old = $em->getRepository(RefreshToken::class)->findOneBy(['token' => 'rotated-token']);

        $this->assertNotNull($old, 'Rotated token is kept for reload-race idempotency');
        $this->assertNotNull($old->getReplacedByToken(), 'Rotated token points at its successor');
        $this->assertLessThan(
            new \DateTimeImmutable('+120 seconds'),
            $old->getExpiresAt(),
            'Rotated token lifetime is cut to the grace window, not the full TTL',
        );
    }

    #[Test]
    public function refreshReturnsValidJwtFormat(): void
    {
        $this->createUserWithRefreshToken('jwt@example.com', 'jwt-refresh-token');

        $response = $this->sendRefreshRequest('jwt-refresh-token');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);

        $parts = explode('.', (string) $data['token']);
        $this->assertCount(3, $parts, 'JWT should have 3 dot-separated parts');
    }

    #[Test]
    public function refreshDeletedAccountReturns401(): void
    {
        // A lingering refresh token must not re-authenticate a deleted account.
        $user = $this->createUserWithRefreshToken('deleted-refresh@example.com', 'deleted-refresh-token');
        $em = $this->getEntityManager();
        $user->anonymize();
        $em->flush();

        $response = $this->sendRefreshRequest('deleted-refresh-token');

        $this->assertResponseStatusCodeSame(401);

        // The deleted-account branch clears the refresh cookie: a refresh_token
        // Set-Cookie must be present and expired (Max-Age=0 / 1970), not absent.
        $setCookie = $response->getHeaders(false)['set-cookie'][0] ?? '';
        $this->assertStringContainsString('refresh_token=', $setCookie);
        $this->assertMatchesRegularExpression(
            '/Max-Age=0|expires=Thu, 01-Jan-1970/i',
            $setCookie,
        );
    }
}
