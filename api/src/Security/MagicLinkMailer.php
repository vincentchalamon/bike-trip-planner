<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\FrontendUrl;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends a magic link to its user, in the user's language: the invitation an operator issues
 * (`app:create-user`) or the sign-in link a visitor requests (`/auth/request-link`).
 *
 * A send failure is the caller's to handle: the command lets it surface, the endpoint must
 * answer neutrally whatever happens.
 */
final readonly class MagicLinkMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private FrontendUrl $frontendUrl,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendInvitation(User $user, MagicLink $magicLink): void
    {
        $this->send($user, $magicLink, 'email/invitation.html.twig', 'auth.email.invitation.subject');
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendSignInLink(User $user, MagicLink $magicLink): void
    {
        $this->send($user, $magicLink, 'email/magic_link.html.twig', 'auth.email.magic_link.subject');
    }

    private function send(User $user, MagicLink $magicLink, string $template, string $subject): void
    {
        // getPlainToken() (not getToken(), which is the hash stored at rest): the link must carry
        // the plaintext the verify endpoint will hash (SEC-003). It rides in the fragment, which
        // a browser never sends, so it stays out of the access logs and the Referer.
        $verifyUrl = $this->frontendUrl->to('/auth/verify#'.$magicLink->getPlainToken());
        $locale = $user->getLocale();

        $html = $this->twig->render($template, [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => MagicLinkRepository::TTL_MINUTES,
            'locale' => $locale,
        ]);

        $this->mailer->send(new Email()
            ->to($user->getEmail())
            ->subject($this->translator->trans($subject, [], 'auth', $locale))
            ->html($html));
    }
}
