<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\EmailChangeToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class EmailChangeTest extends ApiTestCase
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
     * @return array{user: User, jwt: string}
     */
    private function createUser(string $email): array
    {
        $em = $this->getEntityManager();

        $user = new User($email);
        $em->persist($user);
        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return ['user' => $user, 'jwt' => $jwtManager->create($user)];
    }

    /**
     * Recipient addresses of every email sent during the test (deduplicated):
     * the Messenger-backed mailer logs each message more than once, so collapse
     * to the distinct set of "to" addresses to assert delivery target.
     *
     * @return list<string>
     */
    private function getSentRecipients(): array
    {
        /** @var MessageLoggerListener $logger */
        $logger = self::getContainer()->get('mailer.message_logger_listener');

        $recipients = [];
        foreach ($logger->getEvents()->getMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            foreach ($message->getTo() as $address) {
                $recipients[$address->getAddress()] = true;
            }
        }

        return array_keys($recipients);
    }

    #[Test]
    public function requestSendsConfirmationToNewAddressAndCreatesToken(): void
    {
        $fixtures = $this->createUser('alice@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'alice-new@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        // The confirmation email goes to the NEW address, never the current one.
        $this->assertSame(['alice-new@example.com'], $this->getSentRecipients());

        $token = $this->getEntityManager()->getRepository(EmailChangeToken::class)->findOneBy(['newEmail' => 'alice-new@example.com']);
        $this->assertInstanceOf(EmailChangeToken::class, $token);
        $this->assertNull($token->getConsumedAt());
    }

    #[Test]
    public function requestRequiresAuthentication(): void
    {
        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['newEmail' => 'someone@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function requestWithInvalidEmailReturns422(): void
    {
        $fixtures = $this->createUser('invalid-fmt@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'not-an-email'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestWithSameEmailReturns422(): void
    {
        $fixtures = $this->createUser('same@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'same@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestWithAlreadyUsedEmailReturns422(): void
    {
        $this->createUser('taken@example.com');
        $fixtures = $this->createUser('requester@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'taken@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    private function createTokenForUser(
        User $user,
        string $newEmail,
        string $token,
        ?\DateTimeImmutable $expiresAt = null,
    ): EmailChangeToken {
        $em = $this->getEntityManager();
        $entity = new EmailChangeToken(
            $user,
            $token,
            $newEmail,
            $expiresAt ?? new \DateTimeImmutable('+30 minutes'),
        );
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    #[Test]
    public function verifyValidTokenUpdatesEmail(): void
    {
        $fixtures = $this->createUser('verify-old@example.com');
        $this->createTokenForUser($fixtures['user'], 'verify-new@example.com', 'valid-change-token');

        $response = self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'valid-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(['email' => 'verify-new@example.com'], $response->toArray(false));

        $em = $this->getEntityManager();
        $em->clear();

        $reloaded = $em->getRepository(User::class)->find($fixtures['user']->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('verify-new@example.com', $reloaded->getEmail());
    }

    #[Test]
    public function verifyExpiredTokenReturns401(): void
    {
        $fixtures = $this->createUser('expired-old@example.com');
        $this->createTokenForUser(
            $fixtures['user'],
            'expired-new@example.com',
            'expired-change-token',
            new \DateTimeImmutable('-1 day'),
        );

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'expired-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyAlreadyConsumedTokenReturns401(): void
    {
        $fixtures = $this->createUser('reuse-old@example.com');
        $this->createTokenForUser($fixtures['user'], 'reuse-new@example.com', 'reuse-change-token');

        $client = self::createClient();

        $client->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'reuse-change-token'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        // A second verify with the same single-use token must fail.
        $client->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'reuse-change-token'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyTokenBelongingToAnotherUserReturns401(): void
    {
        $owner = $this->createUser('owner@example.com');
        $attacker = $this->createUser('attacker@example.com');
        $this->createTokenForUser($owner['user'], 'stolen@example.com', 'someone-elses-token');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$attacker['jwt']],
            'json' => ['token' => 'someone-elses-token'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function verifyTargetEmailTakenAfterRequestReturns422(): void
    {
        $fixtures = $this->createUser('race-old@example.com');
        $this->createTokenForUser($fixtures['user'], 'race-target@example.com', 'race-change-token');

        // Another account claims the target address before verification.
        $this->createUser('race-target@example.com');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'race-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyWithEmptyTokenReturns422(): void
    {
        $fixtures = $this->createUser('empty-token@example.com');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function noEmailIsSentWhenTargetIsAlreadyTaken(): void
    {
        $this->createUser('occupied@example.com');
        $fixtures = $this->createUser('hopeful@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'occupied@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->getSentRecipients());
    }
}
