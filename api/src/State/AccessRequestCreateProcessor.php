<?php

declare(strict_types=1);

namespace App\State;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AccessRequest as AccessRequestDto;
use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use App\Service\AccessRequestHmacService;
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
 * Handles access request creation: rate limiting, email deduplication, HMAC link generation and email sending.
 *
 * Always returns the same neutral 202 response to prevent email enumeration.
 *
 * @implements ProcessorInterface<AccessRequestDto, JsonResponse>
 */
final readonly class AccessRequestCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccessRequestRepository $accessRequestRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private AccessRequestHmacService $hmacService,
        #[Autowire(service: 'limiter.access_request_ip')]
        private RateLimiterFactory $accessRequestIpLimiter,
        #[Autowire(env: 'BACKEND_URL')]
        private string $backendUrl = 'https://localhost',
        #[Autowire(env: 'MAILER_SENDER_EMAIL')]
        private string $senderEmail = 'noreply@bike-trip-planner.com',
    ) {
    }

    /**
     * @param AccessRequestDto $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $email = $data->email;
        \assert('' !== $email);
        $request = $this->requestStack->getCurrentRequest();
        $clientIp = $request?->getClientIp() ?? 'unknown';

        $neutralMessage = $this->translator->trans('access_request.neutral_message', [], 'access_request');

        // Rate limit by IP: max 3 requests per hour
        $ipLimiter = $this->accessRequestIpLimiter->create($clientIp);
        if (!$ipLimiter->consume()->isAccepted()) {
            $this->logger->debug('Access request IP rate limited', ['ip' => $clientIp]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Silently ignore if user already exists
        $existingUser = $this->userRepository->findByEmail($email);
        if ($existingUser instanceof User) {
            $this->logger->debug('Access request for existing user — silently ignored', ['email' => $email]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Silently ignore if access request already exists
        $existingRequest = $this->accessRequestRepository->findByEmail($email);
        if ($existingRequest instanceof AccessRequest) {
            $this->logger->debug('Access request already exists — silently ignored', ['email' => $email]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Persist new access request
        $accessRequest = new AccessRequest($email, $clientIp);
        $this->entityManager->persist($accessRequest);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Access request race condition — silently ignored', ['email' => $email]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Generate HMAC-signed verification URL
        $payload = $this->hmacService->generatePayload($email);
        $verifyUrl = \sprintf(
            '%s/access-requests/verify?email=%s&expires=%d&signature=%s',
            rtrim($this->backendUrl, '/'),
            urlencode($payload['email']),
            $payload['expires'],
            $payload['signature'],
        );

        $html = $this->twig->render('email/access_request_verify.html.twig', [
            'verifyUrl' => $verifyUrl,
            'locale' => $this->translator->getLocale(),
        ]);

        $emailMessage = new Email()
            ->from(new Address($this->senderEmail, 'Bike Trip Planner'))
            ->to($email)
            ->subject($this->translator->trans('access_request.email.verify.subject', [], 'access_request'))
            ->html($html);

        $this->mailer->send($emailMessage);

        $this->logger->debug('Access request created and verification email sent', ['email' => $email]);

        return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
    }
}
