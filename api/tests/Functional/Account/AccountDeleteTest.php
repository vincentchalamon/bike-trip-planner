<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Entity\AccessRequest;
use App\Entity\MagicLink;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\MagicLinkRepository;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AccountDeleteTest extends ApiTestCase
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

    /**
     * @param non-empty-string $email
     *
     * @return array{user: User, jwt: string, refreshToken: RefreshToken, tripId: string}
     */
    private function createUserWithTrip(string $email): array
    {
        $em = $this->getEntityManager();

        $user = new User($email);
        $em->persist($user);

        $refreshToken = RefreshToken::issue(
            $user,
            self::getContainer()->get(RefreshTokenEncryptor::class),
            bin2hex(random_bytes(32)),
            new \DateTimeImmutable('+30 days'),
        );
        $em->persist($refreshToken);

        $tripId = Uuid::v7();
        $trip = new TripRequest($tripId);
        $trip->user = $user;
        $trip->fatigueFactor = 0.8;

        $em->persist($trip);

        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $jwt = $jwtManager->create($user);

        return ['user' => $user, 'jwt' => $jwt, 'refreshToken' => $refreshToken, 'tripId' => $tripId->toRfc4122()];
    }

    #[Test]
    public function deleteAccountReturns204(): void
    {
        $fixtures = $this->createUserWithTrip('erase@example.com');

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function deleteAccountAnonymizesEmailAndSoftDeletes(): void
    {
        $fixtures = $this->createUserWithTrip('anonymize@example.com');
        $userId = $fixtures['user']->getId()->toRfc4122();

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $em = $this->getEntityManager();
        $em->clear();

        $user = $em->find(User::class, Uuid::fromString($userId));

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->isDeleted());
        $this->assertNotSame('anonymize@example.com', $user->getEmail());
        $this->assertStringEndsWith('@deleted.invalid', $user->getEmail());
    }

    #[Test]
    public function deleteAccountPurgesTripsAndPreferences(): void
    {
        $fixtures = $this->createUserWithTrip('purge@example.com');
        $tripId = $fixtures['tripId'];

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $em = $this->getEntityManager();
        $em->clear();

        $this->assertNull($em->find(TripRequest::class, Uuid::fromString($tripId)));
    }

    #[Test]
    public function deleteAccountRevokesRefreshTokens(): void
    {
        $fixtures = $this->createUserWithTrip('revoke@example.com');
        $userId = $fixtures['user']->getId()->toRfc4122();

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        /** @var RefreshTokenRepository $repo */
        $repo = self::getContainer()->get(RefreshTokenRepository::class);
        $em = $this->getEntityManager();
        $em->clear();

        $user = $em->find(User::class, Uuid::fromString($userId));
        \assert($user instanceof User);

        $this->assertCount(0, $repo->findBy(['user' => $user]));
    }

    #[Test]
    public function deleteAccountClearsRefreshTokenCookie(): void
    {
        $fixtures = $this->createUserWithTrip('cookie@example.com');

        $response = self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $setCookie = $response->getHeaders(false)['set-cookie'][0] ?? '';
        $this->assertStringContainsString('refresh_token=', $setCookie);
        // Cookie must be cleared: Symfony stamps a past expiry and Max-Age=0.
        $this->assertMatchesRegularExpression('/Max-Age=0|expires=Thu, 01-Jan-1970/i', $setCookie);
    }

    #[Test]
    public function deleteAccountWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('DELETE', '/users/me');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function deleteAccountPurgesMagicLinks(): void
    {
        $fixtures = $this->createUserWithTrip('magic-purge@example.com');
        $user = $fixtures['user'];
        $userId = $user->getId()->toRfc4122();

        $em = $this->getEntityManager();
        $em->persist(new MagicLink($user, 'lingering-link-token', new \DateTimeImmutable('+30 minutes')));
        $em->flush();

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        /** @var MagicLinkRepository $repo */
        $repo = self::getContainer()->get(MagicLinkRepository::class);
        $em->clear();
        $reloaded = $em->find(User::class, Uuid::fromString($userId));
        \assert($reloaded instanceof User);

        $this->assertCount(0, $repo->findBy(['user' => $reloaded]), 'magic_link rows must be purged on erasure');
    }

    #[Test]
    public function deleteAccountPurgesAccessRequest(): void
    {
        $email = 'access-purge@example.com';
        $em = $this->getEntityManager();
        $em->persist(new AccessRequest($email, '203.0.113.7'));
        $em->flush();

        $fixtures = $this->createUserWithTrip($email);

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        /** @var AccessRequestRepository $repo */
        $repo = self::getContainer()->get(AccessRequestRepository::class);
        $em->clear();

        $this->assertNull($repo->findByEmail($email), 'access_request PII must be purged on erasure');
    }

    #[Test]
    public function deletedAccountJwtIsRejected(): void
    {
        // After erasure, a JWT minted for the account must no longer authenticate
        // (DeletedUserChecker rejects the soft-deleted user on the per-request
        // JWT reload).
        $fixtures = $this->createUserWithTrip('ghost@example.com');

        self::createClient()->request('DELETE', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        self::createClient()->request('GET', '/trips', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }
}
