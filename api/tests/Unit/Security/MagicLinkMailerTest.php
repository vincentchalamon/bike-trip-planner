<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use App\Security\MagicLinkMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(MagicLinkMailer::class)]
final class MagicLinkMailerTest extends TestCase
{
    private ?RawMessage $sent = null;

    #[Test]
    public function theInvitationCarriesTheRepositoryTtlAndTheFragmentToken(): void
    {
        $user = new User('invitee@example.com');
        $user->setLocale('en');

        $this->mailer()->sendInvitation($user, $this->link($user));

        $email = $this->sent;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('invitee@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('auth.email.invitation.subject|en', $email->getSubject());
        self::assertSame(
            \sprintf('invitation https://app.example/auth/verify#plain-token %d en', MagicLinkRepository::TTL_MINUTES),
            $email->getHtmlBody(),
        );
    }

    #[Test]
    public function theSignInLinkUsesItsOwnTemplateAndSubject(): void
    {
        $user = new User('member@example.com');
        $user->setLocale('fr');

        $this->mailer()->sendSignInLink($user, $this->link($user));

        $email = $this->sent;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('auth.email.magic_link.subject|fr', $email->getSubject());
        self::assertSame(
            \sprintf('sign-in https://app.example/auth/verify#plain-token %d fr', MagicLinkRepository::TTL_MINUTES),
            $email->getHtmlBody(),
        );
    }

    private function mailer(): MagicLinkMailer
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            $this->sent = $message;
        });

        $twig = new Environment(new ArrayLoader([
            'email/invitation.html.twig' => 'invitation {{ verifyUrl }} {{ expiresInMinutes }} {{ locale }}',
            'email/magic_link.html.twig' => 'sign-in {{ verifyUrl }} {{ expiresInMinutes }} {{ locale }}',
        ]));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters, ?string $domain, ?string $locale): string => $id.'|'.$locale,
        );

        return new MagicLinkMailer($mailer, $twig, $translator, 'https://app.example/');
    }

    private function link(User $user): MagicLink
    {
        return new MagicLink($user, 'hash', new \DateTimeImmutable('+30 minutes'), plainToken: 'plain-token');
    }
}
