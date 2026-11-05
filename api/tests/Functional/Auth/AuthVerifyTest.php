<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\ApiTestCase;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AuthVerifyTest extends ApiTestCase
{
    /** The secret of the client that asked for the link (SEC-15). */
    private const string NONCE = 'requesting-client-secret-0123456789abcdef';

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

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
        $magicLink = $repo->issue($user, self::NONCE);
        self::assertInstanceOf(MagicLink::class, $magicLink);
        $plainToken = $magicLink->getPlainToken();
        self::assertIsString($plainToken);
        $repo->save($magicLink);

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => $plainToken, 'nonce' => self::NONCE],
        ]);

        $this->assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $response->toArray());
    }

    private function createUserWithMagicLink(
        string $email = 'test@example.com',
        string $token = 'valid-token-abc123',
        ?\DateTimeImmutable $expiresAt = null,
        ?string $nonce = self::NONCE,
    ): User {
        $em = $this->getEntityManager();
        $user = new User($email);
        $em->persist($user);

        $magicLink = new MagicLink(
            $user,
            // Stored hashed at rest (SEC-003); the plaintext is what /auth/verify receives.
            hash('sha256', $token),
            $expiresAt ?? new \DateTimeImmutable('+30 minutes'),
            nonceHash: null === $nonce ? null : hash('sha256', $nonce),
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
            'json' => ['token' => 'my-valid-token', 'nonce' => self::NONCE],
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
    public function verifyReturnsRefreshTokenInBody(): void
    {
        // The API is client-agnostic: the refresh token is always returned in the
        // body (OAuth-like), never a cookie. The web BFF (step 2) owns the cookie.
        $this->createUserWithMagicLink('bob@example.com', 'body-test-token');

        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'body-test-token', 'nonce' => self::NONCE],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotEmpty($data['refresh_token']);

        // The API sets no cookie any more.
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            $this->assertStringStartsNotWith('refresh_token=', (string) $cookie, 'The API must not set a refresh_token cookie');
        }
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
            'json' => ['token' => 'expired-token', 'nonce' => self::NONCE],
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
            'json' => ['token' => 'consume-me-token', 'nonce' => self::NONCE],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // Second request should fail
        $client->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'consume-me-token', 'nonce' => self::NONCE],
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
            'json' => ['token' => 'jwt-format-token', 'nonce' => self::NONCE],
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
            'json' => ['token' => 'deleted-account-token', 'nonce' => self::NONCE],
        ]);

        $this->assertResponseStatusCodeSame(401);

        // No session must be established for a deleted account: no refresh token.
        $this->assertArrayNotHasKey('refresh_token', $response->toArray(false));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function verify(array $json): array
    {
        $response = self::createClient()->request('POST', '/auth/verify', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => $json,
        ]);

        return ['status' => $response->getStatusCode(), 'body' => $response->toArray(false)];
    }

    /**
     * SEC-15: a link opened without the requesting client's secret names the account it
     * opens and consumes nothing, so the user can still decide, and the right client can
     * still sign in with it.
     */
    #[Test]
    public function verifyWithoutTheNonceAsksForAConfirmationAndConsumesNothing(): void
    {
        $this->createUserWithMagicLink('target@example.com', 'no-nonce-token');

        $asked = $this->verify(['token' => 'no-nonce-token']);
        self::assertSame(409, $asked['status']);
        self::assertSame(['email' => 'target@example.com'], $asked['body']);

        self::assertSame(200, $this->verify(['token' => 'no-nonce-token', 'nonce' => self::NONCE])['status']);
    }

    #[Test]
    public function verifyWithAnotherClientsNonceAsksForAConfirmation(): void
    {
        $this->createUserWithMagicLink('other@example.com', 'wrong-nonce-token');

        $asked = $this->verify(['token' => 'wrong-nonce-token', 'nonce' => str_repeat('x', 40)]);

        self::assertSame(409, $asked['status']);
        self::assertArrayNotHasKey('token', $asked['body']);
        self::assertArrayNotHasKey('refresh_token', $asked['body']);
    }

    #[Test]
    public function aConfirmedVerifySignsInOnceWithoutTheNonce(): void
    {
        $this->createUserWithMagicLink('confirmed@example.com', 'confirm-token');

        $first = $this->verify(['token' => 'confirm-token', 'confirm' => true]);
        self::assertSame(200, $first['status']);
        self::assertArrayHasKey('refresh_token', $first['body']);

        // Still single-use: the confirmation does not reopen a consumed link.
        self::assertSame(401, $this->verify(['token' => 'confirm-token', 'confirm' => true])['status']);
    }

    /** A CLI invitation was asked for by no client, so it always confirms. */
    #[Test]
    public function anUnboundLinkAlwaysAsksForAConfirmation(): void
    {
        $this->createUserWithMagicLink('invited@example.com', 'invitation-token', nonce: null);

        self::assertSame(409, $this->verify(['token' => 'invitation-token', 'nonce' => self::NONCE])['status']);
        self::assertSame(200, $this->verify(['token' => 'invitation-token', 'confirm' => true])['status']);
    }

    /** The confirmation step must not name an account the link cannot open. */
    #[Test]
    public function aDeadLinkIsRefusedBeforeAnyConfirmation(): void
    {
        $this->createUserWithMagicLink('stale@example.com', 'stale-token', new \DateTimeImmutable('-1 minute'));
        $user = $this->createUserWithMagicLink('erased@example.com', 'erased-token');
        $user->anonymize();
        $this->getEntityManager()->flush();

        foreach (['stale-token', 'erased-token', 'unknown-token'] as $token) {
            $refused = $this->verify(['token' => $token]);
            self::assertSame(401, $refused['status'], $token);
            self::assertArrayNotHasKey('email', $refused['body'], $token);
        }
    }
}
