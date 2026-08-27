<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Contracts\HttpClient\ResponseInterface;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
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

        $refreshToken = RefreshToken::issue(
            $user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            $token,
            $expiresAt ?? new \DateTimeImmutable('+30 days'),
        );
        $em->persist($refreshToken);
        $em->flush();

        return $user;
    }

    /**
     * Sends a refresh request with the refresh token in the request body
     * (OAuth-like, client-agnostic).
     */
    private function sendRefreshRequest(string $refreshToken): ResponseInterface
    {
        return self::createClient()->request('POST', '/auth/refresh', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['refresh_token' => $refreshToken],
        ]);
    }

    /**
     * Extracts the rotated refresh token from the response body.
     */
    private function refreshBodyValue(ResponseInterface $response): string
    {
        $data = $response->toArray(false);
        self::assertArrayHasKey('refresh_token', $data, 'No refresh_token in the response body');
        self::assertIsString($data['refresh_token']);

        return $data['refresh_token'];
    }

    #[Test]
    public function storesTheTokenEncryptedAtRest(): void
    {
        // SEC-003: the row must not hold the plaintext credential; it stores a
        // reversible ciphertext, looked up by digest.
        $this->createUserWithRefreshToken('atrest@example.com', 'plaintext-at-rest');

        $em = $this->getEntityManager();
        $em->clear();

        $stored = $em->getRepository(RefreshToken::class)->findOneBy([
            'tokenDigest' => RefreshTokenEncryptor::digest('plaintext-at-rest'),
        ]);
        $this->assertNotNull($stored);
        $this->assertNotSame('plaintext-at-rest', $stored->getEncryptedToken());

        $encryptor = self::getContainer()->get(RefreshTokenEncryptor::class);
        $this->assertSame('plaintext-at-rest', $encryptor->decrypt($stored->getEncryptedToken()));
    }

    #[Test]
    public function refreshFailsClosedWhenSuccessorCiphertextIsUndecryptable(): void
    {
        // Grace-window path (SEC-003): the successor loaded from the DB must be
        // decrypted to be re-served. If it was encrypted under a since-rotated key,
        // decrypt() returns null and the request must fail closed (401), never 500
        // or a garbage token.
        $em = $this->getEntityManager();
        $user = new User('rotated-key@example.com');
        $grace = new \DateTimeImmutable('+20 seconds');

        // Successor ciphertext under a since-rotated key → undecryptable by the app key.
        $successor = RefreshToken::issue($user, new RefreshTokenEncryptor('a-since-rotated-key'), 'successor-plain', $grace);

        // Predecessor still in its grace window, pointing at the successor's digest.
        $predecessor = RefreshToken::issue($user, self::getContainer()->get(RefreshTokenEncryptor::class), 'predecessor-plain', $grace);
        $predecessor->replaceWith(RefreshTokenEncryptor::digest('successor-plain'), $grace);

        $em->persist($user);
        $em->persist($successor);
        $em->persist($predecessor);
        $em->flush();
        $em->clear();

        $this->sendRefreshRequest('predecessor-plain');

        $this->assertResponseStatusCodeSame(401);
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
    public function refreshRotatesTheRefreshToken(): void
    {
        $this->createUserWithRefreshToken('rotate@example.com', 'old-refresh-token');

        $response = $this->sendRefreshRequest('old-refresh-token');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEquals('old-refresh-token', $this->refreshBodyValue($response));

        // The API returns the token in the body and sets no cookie.
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            $this->assertStringStartsNotWith('refresh_token=', (string) $cookie, 'The API must not set a refresh_token cookie');
        }
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
    public function refreshWithMissingTokenReturns422(): void
    {
        // The input DTO validates refresh_token as NotBlank: a missing/empty token
        // is a validation violation (422), before the processor runs.
        self::createClient()->request('POST', '/auth/refresh', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
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
        $successor = $this->refreshBodyValue($first);

        $second = $this->sendRefreshRequest('single-use-token');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($successor, $this->refreshBodyValue($second));
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

        $old = $em->getRepository(RefreshToken::class)->findOneBy([
            'tokenDigest' => RefreshTokenEncryptor::digest('rotated-token'),
        ]);

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

        // No refreshed session for a deleted account: no refresh token issued.
        $this->assertArrayNotHasKey('refresh_token', $response->toArray(false));
    }

    #[Test]
    public function reusingARotatedTokenPastGraceRevokesTheWholeFamily(): void
    {
        // OAuth reuse detection: replaying an already-rotated token AFTER its grace
        // window is treated as theft — the whole family is revoked so the
        // attacker's live successor dies too (not just a 401 on the stale token).
        $em = $this->getEntityManager();
        $encryptor = self::getContainer()->get(RefreshTokenEncryptor::class);
        $user = new User('reuse-theft@example.com');
        $past = new \DateTimeImmutable('-1 second');

        $successor = RefreshToken::issue($user, $encryptor, 'successor-live', new \DateTimeImmutable('+30 days'));
        $predecessor = RefreshToken::issue($user, $encryptor, 'stolen-predecessor', $past);
        $predecessor->replaceWith(RefreshTokenEncryptor::digest('successor-live'), $past);

        $em->persist($user);
        $em->persist($successor);
        $em->persist($predecessor);
        $em->flush();
        $em->clear();

        $this->sendRefreshRequest('stolen-predecessor');
        $this->assertResponseStatusCodeSame(401);

        $repo = $em->getRepository(RefreshToken::class);
        $this->assertNull(
            $repo->findOneBy(['tokenDigest' => RefreshTokenEncryptor::digest('stolen-predecessor')]),
            'The replayed token is revoked',
        );
        $this->assertNull(
            $repo->findOneBy(['tokenDigest' => RefreshTokenEncryptor::digest('successor-live')]),
            'The live successor is revoked too (whole-family revocation)',
        );
    }
}
