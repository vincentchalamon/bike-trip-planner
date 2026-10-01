<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\ApiTestCase;
use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DeviceTokenTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function persistToken(User $user, string $token, DevicePlatform $platform): DeviceToken
    {
        $em = $this->getEntityManager();
        $entity = new DeviceToken($user, $token, $platform);
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    private function countTokens(): int
    {
        return $this->getEntityManager()->getRepository(DeviceToken::class)->count([]);
    }

    #[Test]
    public function registerNewTokenReturns201(): void
    {
        $fixtures = $this->createAuthenticatedUser('register@example.com');

        $response = self::createClient()->request('POST', '/users/me/device-tokens', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'fcm-token-abc', 'platform' => 'android'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $body = $response->toArray(false);
        $this->assertSame('fcm-token-abc', $body['token']);
        $this->assertSame('android', $body['platform']);
        // createdAt is stored and rendered in UTC (offset +00:00), regardless of
        // the server timezone (CI runs Europe/Paris).
        $this->assertStringEndsWith('+00:00', $body['createdAt']);

        $this->assertSame(1, $this->countTokens());
    }

    #[Test]
    public function reRegisterSameTokenReturns200AndDoesNotDuplicate(): void
    {
        $fixtures = $this->createAuthenticatedUser('reregister@example.com');
        $this->persistToken($fixtures['user'], 'fcm-dupe', DevicePlatform::ANDROID);

        self::createClient()->request('POST', '/users/me/device-tokens', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'fcm-dupe', 'platform' => 'ios'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $this->countTokens(), 're-registering the same token must not duplicate');

        $em = $this->getEntityManager();
        $em->clear();

        $reloaded = $em->getRepository(DeviceToken::class)->findOneBy(['token' => 'fcm-dupe']);
        $this->assertInstanceOf(DeviceToken::class, $reloaded);
        $this->assertSame(DevicePlatform::IOS, $reloaded->getPlatform(), 'platform must be updated in place');
    }

    #[Test]
    public function reRegisterTokenOfAnotherUserReassignsIt(): void
    {
        $owner = $this->createAuthenticatedUser('previous-owner@example.com');
        $newOwner = $this->createAuthenticatedUser('new-owner@example.com');
        $this->persistToken($owner['user'], 'fcm-shared-device', DevicePlatform::ANDROID);

        self::createClient()->request('POST', '/users/me/device-tokens', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$newOwner['jwt']],
            'json' => ['token' => 'fcm-shared-device', 'platform' => 'android'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $this->countTokens());

        $em = $this->getEntityManager();
        $em->clear();

        $reloaded = $em->getRepository(DeviceToken::class)->findOneBy(['token' => 'fcm-shared-device']);
        $this->assertInstanceOf(DeviceToken::class, $reloaded);
        $this->assertTrue($reloaded->getUser()->getId()->equals($newOwner['user']->getId()), 'token must be reassigned to the new owner');
    }

    #[Test]
    public function registerRequiresAuthentication(): void
    {
        self::createClient()->request('POST', '/users/me/device-tokens', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'fcm-anon', 'platform' => 'android'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function registerWithUnknownPlatformReturns422(): void
    {
        // An unknown backed-enum value fails denormalization and surfaces as 422
        // (a validation violation), never 400 (ADR / API Platform 4.3 contract).
        $fixtures = $this->createAuthenticatedUser('bad-platform@example.com');

        self::createClient()->request('POST', '/users/me/device-tokens', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'fcm-bad', 'platform' => 'windows-phone'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->countTokens());
    }

    #[Test]
    public function deleteReturns204(): void
    {
        $fixtures = $this->createAuthenticatedUser('unregister@example.com');
        $this->persistToken($fixtures['user'], 'fcm-to-delete', DevicePlatform::IOS);

        self::createClient()->request('POST', '/users/me/device-tokens/unregister', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'fcm-to-delete'],
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->countTokens());
    }

    #[Test]
    public function deleteTokenOfAnotherUserReturns404AndDoesNotDeleteIt(): void
    {
        // The delete is scoped to the caller's own tokens, so a foreign token is
        // "not found" -> 404 (indistinguishable from a missing one) and is never
        // touched.
        $owner = $this->createAuthenticatedUser('token-owner@example.com');
        $attacker = $this->createAuthenticatedUser('token-attacker@example.com');
        $this->persistToken($owner['user'], 'fcm-not-yours', DevicePlatform::ANDROID);

        self::createClient()->request('POST', '/users/me/device-tokens/unregister', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$attacker['jwt']],
            'json' => ['token' => 'fcm-not-yours'],
        ]);

        $this->assertResponseStatusCodeSame(404);

        // The foreign token must survive: the rightful owner can still use it.
        $em = $this->getEntityManager();
        $em->clear();
        $this->assertNotNull($em->getRepository(DeviceToken::class)->findOneBy(['token' => 'fcm-not-yours']), 'a foreign token must never be deleted by a non-owner');
    }

    #[Test]
    public function deleteUnknownTokenReturns404(): void
    {
        $fixtures = $this->createAuthenticatedUser('delete-unknown@example.com');

        self::createClient()->request('POST', '/users/me/device-tokens/unregister', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'fcm-does-not-exist'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unregisterWithoutATokenReturns422(): void
    {
        $fixtures = $this->createAuthenticatedUser('unregister-blank@example.com');

        self::createClient()->request('POST', '/users/me/device-tokens/unregister', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    /** The token is what a push is addressed to: it never rides in a URL. */
    #[Test]
    public function theTokenIsNoLongerAcceptedInThePath(): void
    {
        $fixtures = $this->createAuthenticatedUser('unregister-path@example.com');
        $this->persistToken($fixtures['user'], 'fcm-in-path', DevicePlatform::IOS);

        self::createClient()->request('DELETE', '/users/me/device-tokens/fcm-in-path', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertSame(1, $this->countTokens());
    }

    #[Test]
    public function deleteRequiresAuthentication(): void
    {
        self::createClient()->request('POST', '/users/me/device-tokens/unregister', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['token' => 'fcm-anon'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
