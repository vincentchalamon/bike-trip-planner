<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\ApiTestCase;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AuthLogoutTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{user: User, jwt: string, refreshToken: RefreshToken}
     */
    private function createUserWithRefreshToken(string $email): array
    {
        $auth = $this->createAuthenticatedUser($email);

        $refreshToken = RefreshToken::issue(
            $auth['user'],
            self::getContainer()->get(RefreshTokenEncryptor::class),
            bin2hex(random_bytes(32)),
            new \DateTimeImmutable('+30 days'),
        );
        $em = $this->getEntityManager();
        $em->persist($refreshToken);
        $em->flush();

        return $auth + ['refreshToken' => $refreshToken];
    }

    #[Test]
    public function logoutAuthenticatedUserReturns204(): void
    {
        $auth = $this->createUserWithRefreshToken('logout@example.com');

        self::createClient()->request('POST', '/auth/logout', [
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Authorization' => 'Bearer '.$auth['jwt'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function logoutRevokesAllRefreshTokens(): void
    {
        $auth = $this->createUserWithRefreshToken('revoke@example.com');

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
