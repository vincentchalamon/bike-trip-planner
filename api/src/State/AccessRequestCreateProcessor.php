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
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Psr\Clock\ClockInterface;
use App\RateLimiter\RetryAfter;
use App\Logger\EmailFingerprint;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Handles access request creation: rate limiting, email deduplication, HMAC link generation and email sending.
 *
 * Returns 429 when the IP rate limit is exceeded; returns 202 for all other normal cases (new request, duplicate email, existing user) to prevent email enumeration.
 * Answers 503 on a mail failure after removing the persisted record, so the client can retry.
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
        #[Target('access_request_ip')]
        private RateLimiterFactoryInterface $accessRequestIpLimiter,
        private ClockInterface $clock,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl = 'https://localhost',
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
        $ipLimit = $this->accessRequestIpLimiter->create($clientIp)->consume();
        if (!$ipLimit->isAccepted()) {
            // No IP: the limiter already keys on it, the edge access log has it, and a
            // hash of an IPv4 address is reversed by enumerating 2^32 values.
            $this->logger->debug('Access request IP rate limited');

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) RetryAfter::seconds($ipLimit, $this->clock),
            ]);
        }

        // Silently ignore if user already exists
        $existingUser = $this->userRepository->findByEmail($email);
        if ($existingUser instanceof User) {
            $this->logger->debug('Access request for existing user — silently ignored', ['user' => $existingUser->getId()->toRfc4122()]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Silently ignore if access request already exists
        $existingRequest = $this->accessRequestRepository->findByEmail($email);
        if ($existingRequest instanceof AccessRequest) {
            $this->logger->debug('Access request already exists — silently ignored', ['emailHash' => EmailFingerprint::of($email)]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Persist new access request
        $accessRequest = new AccessRequest($email, $clientIp);
        $this->entityManager->persist($accessRequest);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Access request race condition — silently ignored', ['emailHash' => EmailFingerprint::of($email)]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        // Generate HMAC-signed verification URL. It points at the FRONTEND route
        // (like the magic-link and email-change emails): the /access-requests/verify
        // page then POSTs to the backend. Using FRONTEND_URL means the link uses the
        // public origin (e.g. the ngrok host in mobile testing) instead of the
        // internal https://localhost. It names the request by id, never by address,
        // and carries it in the fragment, which a browser never sends: nothing of it
        // reaches an access log or a Referer.
        $payload = $this->hmacService->generatePayload($accessRequest->getId()->toRfc4122());
        $verifyUrl = \sprintf(
            '%s/access-requests/verify#%s',
            rtrim($this->frontendUrl, '/'),
            http_build_query($payload),
        );

        $html = $this->twig->render('email/access_request_verify.html.twig', [
            'verifyUrl' => $verifyUrl,
            'locale' => $this->translator->getLocale(),
        ]);

        $emailMessage = new Email()
            ->to($email)
            ->subject($this->translator->trans('access_request.email.verify.subject', [], 'access_request'))
            ->html($html);

        try {
            $this->mailer->send($emailMessage);
        } catch (TransportExceptionInterface $transportException) {
            // The class and code only, and a 503 without the SMTP text: a rejection
            // quotes the recipient. Unlike the magic link, this one is not answered
            // neutrally: the record is gone, and a 202 would leave the requester
            // waiting for an email that never comes instead of trying again.
            $this->logger->error('Failed to send access request verification email — removing record to allow retry', [
                'emailHash' => EmailFingerprint::of($email),
                'error' => $transportException::class,
                'code' => $transportException->getCode(),
            ]);
            $this->entityManager->remove($accessRequest);
            $this->entityManager->flush();

            throw new ServiceUnavailableHttpException(message: $this->translator->trans('access_request.error.mail_failed', [], 'access_request'), previous: $transportException, code: $transportException->getCode());
        }

        $this->logger->debug('Access request created and verification email sent', ['emailHash' => EmailFingerprint::of($email)]);

        return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
    }
}
