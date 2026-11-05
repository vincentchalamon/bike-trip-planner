<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\ApiTestCase;
use App\Tests\Functional\FailingMailerTrait;
use App\Entity\EmailChangeToken;
use App\Entity\RefreshToken;
use App\Security\AuthCookies;
use App\Security\RefreshTokenEncryptor;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class EmailChangeTest extends ApiTestCase
{
    use FailingMailerTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
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
        $fixtures = $this->createAuthenticatedUser('alice@example.com');

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
    public function confirmationLinkCarriesTheTokenInTheFragment(): void
    {
        $fixtures = $this->createAuthenticatedUser('fragment@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'fragment-new@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        /** @var MessageLoggerListener $logger */
        $logger = self::getContainer()->get('mailer.message_logger_listener');
        $message = $logger->getEvents()->getMessages()[0] ?? null;
        $this->assertInstanceOf(Email::class, $message);
        $this->assertStringContainsString('/account/email-change/verify#', (string) $message->getHtmlBody());
        $this->assertStringNotContainsString('/account/email-change/verify/', (string) $message->getHtmlBody());
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
        $fixtures = $this->createAuthenticatedUser('invalid-fmt@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'not-an-email'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestWithSameEmailReturns422(): void
    {
        $fixtures = $this->createAuthenticatedUser('same@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'same@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function requestWithAlreadyUsedEmailReturns422(): void
    {
        $this->createAuthenticatedUser('taken@example.com');
        $fixtures = $this->createAuthenticatedUser('requester@example.com');

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
            // Stored hashed at rest (SEC-003); the plaintext is what the verify endpoint receives.
            hash('sha256', $token),
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
        $fixtures = $this->createAuthenticatedUser('verify-old@example.com');
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

    /**
     * Whoever completes the change may be someone holding a stolen session: the old address is
     * told, and every other session of the account ends. The caller's own, identified by its
     * refresh cookie, survives, or the change would sign out the person who just made it.
     */
    #[Test]
    public function verifyNotifiesThePreviousAddressAndEndsTheOtherSessions(): void
    {
        $fixtures = $this->createAuthenticatedUser('notice-old@example.com');
        $user = $fixtures['user'];
        $encryptor = self::getContainer()->get(RefreshTokenEncryptor::class);

        $em = $this->getEntityManager();
        $em->persist(RefreshToken::issue($user, $encryptor, 'caller-session', new \DateTimeImmutable('+30 days')));
        $em->persist(RefreshToken::issue($user, $encryptor, 'other-device', new \DateTimeImmutable('+30 days')));

        $bystander = $this->createAuthenticatedUser('notice-bystander@example.com')['user'];
        $em->persist(RefreshToken::issue($bystander, $encryptor, 'bystander-session', new \DateTimeImmutable('+30 days')));
        $em->flush();
        $this->createTokenForUser($user, 'notice-new@example.com', 'notice-change-token');

        $client = self::createClient();
        $client->getCookieJar()->set(new BrowserKitCookie(AuthCookies::REFRESH_TOKEN, 'caller-session', null, '/', 'localhost'));
        $client->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'notice-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(['notice-old@example.com'], $this->getSentRecipients(), 'The notice goes to the previous address, and only there.');

        $remaining = array_map(
            static fn (RefreshToken $token): string => $token->getTokenDigest(),
            $this->getEntityManager()->getRepository(RefreshToken::class)->findAll(),
        );
        sort($remaining);
        $expected = [RefreshTokenEncryptor::digest('caller-session'), RefreshTokenEncryptor::digest('bystander-session')];
        sort($expected);
        $this->assertSame($expected, $remaining);
    }

    /** Without a cookie (a mobile client) the caller cannot be told apart: every session ends. */
    #[Test]
    public function verifyWithoutARefreshCookieEndsEverySession(): void
    {
        $fixtures = $this->createAuthenticatedUser('nocookie-old@example.com');
        $em = $this->getEntityManager();
        $em->persist(RefreshToken::issue($fixtures['user'], self::getContainer()->get(RefreshTokenEncryptor::class), 'some-device', new \DateTimeImmutable('+30 days')));
        $em->flush();
        $this->createTokenForUser($fixtures['user'], 'nocookie-new@example.com', 'nocookie-change-token');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'nocookie-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(0, $this->getEntityManager()->getRepository(RefreshToken::class)->count([]));
    }

    /** A change that did not happen ends nothing and tells nobody. */
    #[Test]
    public function aRefusedVerifyKeepsTheSessionsAndSendsNothing(): void
    {
        $fixtures = $this->createAuthenticatedUser('refused-old@example.com');
        $em = $this->getEntityManager();
        $em->persist(RefreshToken::issue($fixtures['user'], self::getContainer()->get(RefreshTokenEncryptor::class), 'kept-device', new \DateTimeImmutable('+30 days')));
        $em->flush();

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'no-such-token'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->getSentRecipients());
        $this->assertSame(1, $this->getEntityManager()->getRepository(RefreshToken::class)->count([]));
    }

    #[Test]
    public function verifyExpiredTokenReturns422(): void
    {
        // An authenticated user submitting an expired token is a 422
        // (unprocessable), not a 401 — the caller IS authenticated.
        $fixtures = $this->createAuthenticatedUser('expired-old@example.com');
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

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyUnknownTokenReturns422(): void
    {
        $fixtures = $this->createAuthenticatedUser('unknown-token@example.com');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'does-not-exist'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyAlreadyConsumedTokenReturns422(): void
    {
        // A single-use token that was already consumed is rejected (422). The
        // token is pre-consumed so the user's email (and thus their JWT) stays
        // valid — verifying single-use independently of the post-change JWT
        // rotation, which is exercised separately.
        $fixtures = $this->createAuthenticatedUser('reuse-old@example.com');
        $token = $this->createTokenForUser($fixtures['user'], 'reuse-new@example.com', 'reuse-change-token');

        $em = $this->getEntityManager();
        $token->consume();
        $em->flush();

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'reuse-change-token'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyTokenBelongingToAnotherUserReturns403AndDoesNotConsumeIt(): void
    {
        $owner = $this->createAuthenticatedUser('owner@example.com');
        $attacker = $this->createAuthenticatedUser('attacker@example.com');
        $this->createTokenForUser($owner['user'], 'owner-new@example.com', 'someone-elses-token');

        $client = self::createClient();

        // The attacker cannot use the owner's token: rejected with 403.
        $client->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$attacker['jwt']],
            'json' => ['token' => 'someone-elses-token'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        // Crucially, the foreign token must NOT have been consumed: its rightful
        // owner can still complete the change with it.
        // The token is stored hashed at rest (SEC-003), so look it up by digest.
        $token = $this->getEntityManager()->getRepository(EmailChangeToken::class)->findOneBy(['token' => hash('sha256', 'someone-elses-token')]);
        $this->assertInstanceOf(EmailChangeToken::class, $token);
        $this->assertNull($token->getConsumedAt(), 'A foreign token must never be consumed by a non-owner');

        $client->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$owner['jwt']],
            'json' => ['token' => 'someone-elses-token'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $em = $this->getEntityManager();
        $em->clear();

        $reloaded = $em->getRepository(User::class)->find($owner['user']->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('owner-new@example.com', $reloaded->getEmail());
    }

    #[Test]
    public function verifyTargetEmailTakenAfterRequestReturns422(): void
    {
        $fixtures = $this->createAuthenticatedUser('race-old@example.com');
        $this->createTokenForUser($fixtures['user'], 'race-target@example.com', 'race-change-token');

        // Another account claims the target address before verification.
        $this->createAuthenticatedUser('race-target@example.com');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => 'race-change-token'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function verifyWithEmptyTokenReturns422(): void
    {
        $fixtures = $this->createAuthenticatedUser('empty-token@example.com');

        self::createClient()->request('POST', '/users/me/email-change/verify', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['token' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function noEmailIsSentWhenTargetIsAlreadyTaken(): void
    {
        $this->createAuthenticatedUser('occupied@example.com');
        $fixtures = $this->createAuthenticatedUser('hopeful@example.com');

        self::createClient()->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'occupied@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->getSentRecipients());
    }

    /** Answered without the SMTP text, which quotes the recipient. */
    #[Test]
    public function aMailFailureAnswers503WithoutTheSmtpMessage(): void
    {
        $fixtures = $this->createAuthenticatedUser('mailfail@example.com');

        $client = self::createClient();
        $this->failTheMailer();
        $response = $client->request('POST', '/users/me/email-change', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['newEmail' => 'rider@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(503);
        $this->assertStringNotContainsString('550', $response->getContent(false));
    }
}
