<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\EmailChange;
use App\Entity\User;
use App\Repository\EmailChangeTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Requests an email change (#777): validates the new address, creates a
 * single-use token and sends a confirmation link to the NEW address.
 *
 * Distinct from the login magic link (which re-authenticates the same email):
 * the confirmation goes to the address being claimed, so only someone with
 * access to it can complete the change.
 *
 * @implements ProcessorInterface<EmailChange, JsonResponse>
 */
final readonly class RequestEmailChangeProcessor implements ProcessorInterface
{
    private const int TTL_MINUTES = 30;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private EmailChangeTokenRepository $emailChangeTokenRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl = 'https://localhost',
    ) {
    }

    /**
     * @param EmailChange $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $newEmail = mb_strtolower(trim($data->newEmail));

        if ($newEmail === mb_strtolower($user->getEmail())) {
            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.same_email', [], 'account'));
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        if ($existing instanceof User) {
            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.email_taken', [], 'account'));
        }

        $token = $this->emailChangeTokenRepository->create($user, $newEmail);
        $this->entityManager->flush();

        $verifyUrl = \sprintf('%s/account/email-change/verify/%s', rtrim($this->frontendUrl, '/'), $token->getToken());
        $locale = $user->getLocale();

        $html = $this->twig->render('email/email_change.html.twig', [
            'verifyUrl' => $verifyUrl,
            'newEmail' => $newEmail,
            'expiresInMinutes' => self::TTL_MINUTES,
            'locale' => $locale,
        ]);

        $message = new Email()
            ->from(new Address('noreply@bike-trip-planner.com', 'Bike Trip Planner'))
            ->to($newEmail)
            ->subject($this->translator->trans('email_change.email.subject', [], 'account', $locale))
            ->html($html);

        $this->mailer->send($message);

        $this->logger->debug('Email change requested', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(
            ['message' => $this->translator->trans('email_change.requested', [], 'account', $locale)],
            Response::HTTP_ACCEPTED,
        );
    }
}
