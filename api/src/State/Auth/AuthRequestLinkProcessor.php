<?php

declare(strict_types=1);

namespace App\State\Auth;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Handles magic link request: rate limiting, user lookup, link creation, and email sending.
 *
 * Always returns the same neutral message to prevent user enumeration.
 *
 * @implements ProcessorInterface<Auth, JsonResponse>
 */
final readonly class AuthRequestLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MagicLinkRepository $magicLinkRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.magic_link_email')]
        private RateLimiterFactory $magicLinkEmailLimiter,
        #[Autowire(service: 'limiter.magic_link_ip')]
        private RateLimiterFactory $magicLinkIpLimiter,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl = 'https://localhost',
    ) {
    }

    /**
     * @param Auth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $email = $data->email;
        $request = $this->requestStack->getCurrentRequest();
        $clientIp = $request?->getClientIp() ?? 'unknown';

        // Apply rate limiters -- silently deny if exceeded
        $ipLimiter = $this->magicLinkIpLimiter->create($clientIp);
        $emailLimiter = $this->magicLinkEmailLimiter->create($email);

        // Consume both unconditionally to keep counters in sync
        $ipAccepted = $ipLimiter->consume()->isAccepted();
        $emailAccepted = $emailLimiter->consume()->isAccepted();

        $neutralMessage = $this->translator->trans('auth.neutral_message', [], 'auth');

        if (!$ipAccepted || !$emailAccepted) {
            $this->logger->debug('Auth request-link rate limited', ['email' => $email, 'ip' => $clientIp]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->logger->debug('Auth request-link user not found', ['email' => $email]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $magicLink = $this->magicLinkRepository->create($user);

        if (!$magicLink instanceof MagicLink) {
            $this->logger->debug('Auth request-link active link already exists', ['email' => $email]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $verifyUrl = \sprintf('%s/auth/verify/%s', rtrim($this->frontendUrl, '/'), $magicLink->getToken());

        $locale = $user->getLocale();

        $html = $this->twig->render('email/magic_link.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => 30,
            'locale' => $locale,
        ]);

        $emailMessage = new Email()
            ->from(new Address('noreply@bike-trip-planner.com', 'Bike Trip Planner'))
            ->to($user->getEmail())
            ->subject($this->translator->trans('auth.email.magic_link.subject', [], 'auth', $locale))
            ->html($html);

        $this->mailer->send($emailMessage);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Concurrent request already created a link — return neutral response
            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $this->logger->debug('Auth request-link magic link created and sent', ['email' => $email]);

        return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
    }
}
