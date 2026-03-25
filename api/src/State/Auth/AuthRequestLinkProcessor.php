<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\AuthRequestLink;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Security\MagicLinkManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

/**
 * Handles magic link request: rate limiting, user lookup, link creation, and email sending.
 *
 * Always returns the same neutral message to prevent user enumeration.
 *
 * @implements ProcessorInterface<AuthRequestLink, JsonResponse>
 */
final readonly class AuthRequestLinkProcessor implements ProcessorInterface
{
    private const string NEUTRAL_MESSAGE = 'Si votre adresse est enregistrée, vous recevrez un lien de connexion.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MagicLinkManager $magicLinkManager,
        private MailerInterface $mailer,
        private Environment $twig,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.magic_link_email')]
        private RateLimiterFactory $magicLinkEmailLimiter,
        #[Autowire(service: 'limiter.magic_link_ip')]
        private RateLimiterFactory $magicLinkIpLimiter,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl = 'https://localhost',
    ) {
    }

    /**
     * @param AuthRequestLink $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $email = $data->email;
        $request = $this->requestStack->getCurrentRequest();
        $clientIp = $request?->getClientIp() ?? 'unknown';

        // Apply rate limiters -- silently deny if exceeded
        $ipLimiter = $this->magicLinkIpLimiter->create($clientIp);
        $emailLimiter = $this->magicLinkEmailLimiter->create($email);

        if (!$ipLimiter->consume()->isAccepted() || !$emailLimiter->consume()->isAccepted()) {
            $this->logger->debug('Auth request-link rate limited', ['email' => $email, 'ip' => $clientIp]);

            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->logger->debug('Auth request-link user not found', ['email' => $email]);

            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        $magicLink = $this->magicLinkManager->create($user);

        if (!$magicLink instanceof MagicLink) {
            $this->logger->debug('Auth request-link active link already exists', ['email' => $email]);

            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        $verifyUrl = \sprintf('%s/auth/verify/%s', rtrim($this->frontendUrl, '/'), $magicLink->getToken());

        $locale = $request?->getPreferredLanguage(['en', 'fr']) ?? 'fr';

        $html = $this->twig->render('email/magic_link.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => 30,
            'locale' => $locale,
        ]);

        $emailMessage = (new Email())
            ->from(new Address('noreply@bike-trip-planner.com', 'Bike Trip Planner'))
            ->to($user->getEmail())
            ->subject('Votre lien de connexion — Bike Trip Planner')
            ->html($html);

        $this->mailer->send($emailMessage);

        $this->logger->debug('Auth request-link magic link created and sent', ['email' => $email]);

        return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
    }
}
