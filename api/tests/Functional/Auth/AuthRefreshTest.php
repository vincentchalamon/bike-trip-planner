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
    public function refreshTokenCannotBeReusedAfterRotation(): void
    {
        $this->createUserWithRefreshToken('reuse@example.com', 'single-use-token');

        // First refresh should succeed
        $this->sendRefreshRequest('single-use-token');
        $this->assertResponseStatusCodeSame(200);

        // Second refresh with the same token should fail (token was rotated)
        $this->sendRefreshRequest('single-use-token');
        $this->assertResponseStatusCodeSame(401);
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
}
