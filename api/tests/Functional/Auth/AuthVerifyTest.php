<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuthVerifyTest extends ApiTestCase
{
    use Factories;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function createUserWithMagicLink(
        string $email = 'test@example.com',
        string $token = 'valid-token-abc123',
        ?\DateTimeImmutable $expiresAt = null,
    ): User {
        $em = $this->getEntityManager();
        $user = new User($email);
        $em->persist($user);

        $magicLink = new MagicLink(
            $user,
            $token,
            $expiresAt ?? new \DateTimeImmutable('+30 minutes'),
        );
        $em->persist($magicLink);
        $em->flush();

        return $user;
    }

    #[Test]
    public function verifyValidTokenReturnsJwt(): void
    {
        $this->createUserWithMagicLink('alice@example.com', 'my-valid-token');

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'my-valid-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    #[Test]
    public function verifyValidTokenSetsRefreshTokenCookie(): void
    {
        $this->createUserWithMagicLink('bob@example.com', 'cookie-test-token');

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'cookie-test-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $cookies = $response->getHeaders(false)['set-cookie'] ?? [];
        $hasRefreshCookie = false;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, 'refresh_token=')) {
                $hasRefreshCookie = true;
                break;
            }
        }

        $this->assertTrue($hasRefreshCookie, 'Response should set a refresh_token cookie');
    }

    #[Test]
    public function verifyExpiredTokenReturns401(): void
    {
        $this->createUserWithMagicLink(
            'expired@example.com',
            'expired-token',
            new \DateTimeImmutable('-1 hour'),
        );

        self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'expired-token'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyInvalidTokenReturns401(): void
    {
        self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'nonexistent-token-xyz'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyAlreadyConsumedTokenReturns401(): void
    {
        $this->createUserWithMagicLink('consumed@example.com', 'consume-me-token');

        $client = self::createClient();

        // First request consumes the token
        $client->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'consume-me-token'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Second request should fail
        $client->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'consume-me-token'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyEmptyTokenReturns422(): void
    {
        self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyMissingTokenReturns422(): void
    {
        self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyReturnsValidJwtFormat(): void
    {
        $this->createUserWithMagicLink('jwt@example.com', 'jwt-format-token');

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'jwt-format-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);

        // JWT should have 3 dot-separated parts (header.payload.signature)
        $parts = explode('.', (string) $data['token']);
        $this->assertCount(3, $parts, 'JWT should have 3 dot-separated parts');
    }
}
