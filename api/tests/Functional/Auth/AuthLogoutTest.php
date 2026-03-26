<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuthLogoutTest extends ApiTestCase
{
    use Factories;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function createAuthenticatedUser(string $email = 'test@example.com'): array
    {
        $em = $this->getEntityManager();
        $user = new User($email);
        $em->persist($user);

        $refreshToken = new RefreshToken(
            $user,
            bin2hex(random_bytes(32)),
            new \DateTimeImmutable('+30 days'),
        );
        $em->persist($refreshToken);
        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $jwt = $jwtManager->create($user);

        return ['user' => $user, 'jwt' => $jwt, 'refreshToken' => $refreshToken];
    }

    #[Test]
    public function logoutAuthenticatedUserReturns204(): void
    {
        $auth = $this->createAuthenticatedUser('logout@example.com');

        self::createClient()->request('POST', '/auth/logout', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$auth['jwt'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function logoutClearsRefreshTokenCookie(): void
    {
        $auth = $this->createAuthenticatedUser('cookie-clear@example.com');

        $response = self::createClient()->request('POST', '/auth/logout', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$auth['jwt'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(204);

        $cookies = $response->getHeaders(false)['set-cookie'] ?? [];
        $cookieCleared = false;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'refresh_token=')) {
                $cookieCleared = true;
                break;
            }
        }
        $this->assertTrue($cookieCleared, 'Response should clear the refresh_token cookie');
    }

    #[Test]
    public function logoutRevokesAllRefreshTokens(): void
    {
        $auth = $this->createAuthenticatedUser('revoke@example.com');

        self::createClient()->request('POST', '/auth/logout', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$auth['jwt'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(204);

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $remaining = $repo->findBy(['user' => $auth['user']]);
        $this->assertCount(0, $remaining, 'All refresh tokens should be revoked after logout');
    }

    #[Test]
    public function logoutWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('POST', '/auth/logout', [
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function logoutWithInvalidJwtReturns401(): void
    {
        self::createClient()->request('POST', '/auth/logout', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer invalid.jwt.token',
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
