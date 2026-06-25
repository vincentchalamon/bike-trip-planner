<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\EmailChange;
use App\Entity\User;
use App\Repository\EmailChangeTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private EmailChangeTokenRepository $emailChangeTokenRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        #[Autowire(service: 'limiter.email_change_user')]
        private RateLimiterFactory $emailChangeUserLimiter,
        #[Autowire(service: 'limiter.email_change_ip')]
        private RateLimiterFactory $emailChangeIpLimiter,
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

        // Dual rate limiting (per-user + per-IP), mirroring the magic-link endpoint,
        // to throttle confirmation-email spam toward arbitrary addresses (#777 review).
        $userLimit = $this->emailChangeUserLimiter->create($user->getId()->toRfc4122())->consume();
        $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $ipLimit = $this->emailChangeIpLimiter->create($clientIp)->consume();
        if (!$userLimit->isAccepted() || !$ipLimit->isAccepted()) {
            $limit = $userLimit->isAccepted() ? $ipLimit : $userLimit;
            $secondsUntilRetry = max(0, $limit->getRetryAfter()->getTimestamp() - new \DateTimeImmutable()->getTimestamp());

            throw new TooManyRequestsHttpException(retryAfter: $secondsUntilRetry, message: $this->translator->trans('email_change.error.rate_limited', [], 'account'));
        }

        $newEmail = mb_strtolower(trim($data->newEmail));

        if ($newEmail === mb_strtolower($user->getEmail())) {
            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.same_email', [], 'account'));
        }

        $existing = $this->userRepository->findOneBy(['email' => $newEmail]);
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
            'expiresInMinutes' => EmailChangeTokenRepository::TTL_MINUTES,
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
