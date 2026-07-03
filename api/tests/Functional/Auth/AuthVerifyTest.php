<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuthVerifyTest extends ApiTestCase
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

    #[Test]
    public function verifyRealCreatedMagicLinkLogsIn(): void
    {
        // End-to-end round-trip through the real create() path: the emitted
        // plaintext must verify while only its hash is stored. Guards against
        // emitting the stored hash instead of the plaintext (SEC-003 regression,
        // the class of bug that let CreateUserCommand ship the hash).
        $em = $this->getEntityManager();
        $user = new User('roundtrip@example.com');
        $em->persist($user);
        $em->flush();

        /** @var MagicLinkRepository $repo */
        $repo = self::getContainer()->get(MagicLinkRepository::class);
        $magicLink = $repo->create($user);
        self::assertInstanceOf(MagicLink::class, $magicLink);
        $plainToken = $magicLink->getPlainToken();
        self::assertIsString($plainToken);
        $em->flush();

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => $plainToken],
        ]);

        $this->assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $response->toArray());
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
            // Stored hashed at rest (SEC-003); the plaintext is what /auth/verify receives.
            hash('sha256', $token),
            $expiresAt ?? new \DateTimeImmutable('+30 minutes'),
        );
        $em->persist($magicLink);
        $em->flush();

        return $user;
    }

    #[Test]
    public function verifyValidTokenReturnsJwt(): void
    {
        $user = $this->createUserWithMagicLink('alice@example.com', 'my-valid-token');

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'my-valid-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);

        // Assert JwtCreatedListener injects the sub (UUID) claim
        $parts = explode('.', (string) $data['token']);
        /** @var array{sub?: string, username?: string} $payload */
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame($user->getId()->toRfc4122(), $payload['sub'] ?? null, 'JWT must contain sub = user UUID');
        $this->assertSame('alice@example.com', $payload['username'] ?? null, 'JWT must contain username = email');
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
            new \DateTimeImmutable('-1 day'),
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

    #[Test]
    public function verifyDeletedAccountReturns401(): void
    {
        // A still-valid magic link must not re-authenticate a deleted account
        // (GDPR erasure is final). Guards against the auth-bypass where the link
        // outlived the account (magic links are now purged on deletion, but the
        // auth path must reject deleted users regardless).
        $user = $this->createUserWithMagicLink('deleted@example.com', 'deleted-account-token');
        $em = $this->getEntityManager();
        $user->anonymize();
        $em->flush();

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'deleted-account-token'],
        ]);

        $this->assertResponseStatusCodeSame(401);

        // No session must be established for a deleted account.
        $cookies = $response->getHeaders(false)['set-cookie'] ?? [];
        foreach ($cookies as $cookie) {
            $this->assertStringStartsNotWith('refresh_token=', (string) $cookie, 'No refresh cookie for a deleted account');
        }
    }
}
