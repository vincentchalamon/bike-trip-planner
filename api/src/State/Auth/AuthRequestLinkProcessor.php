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
use App\Security\MagicLinkMailer;
use App\Logger\EmailFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

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
        private MagicLinkMailer $magicLinkMailer,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        #[Target('magic_link_email')]
        private RateLimiterFactoryInterface $magicLinkEmailLimiter,
        #[Target('magic_link_ip')]
        private RateLimiterFactoryInterface $magicLinkIpLimiter,
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
            $this->logger->debug('Auth request-link rate limited', ['emailHash' => EmailFingerprint::of($email), 'ipLimited' => !$ipAccepted]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->logger->debug('Auth request-link user not found', ['emailHash' => EmailFingerprint::of($email)]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $magicLink = $this->magicLinkRepository->issue($user);

        if (!$magicLink instanceof MagicLink) {
            $this->logger->debug('Auth request-link active link already exists', ['user' => $user->getId()->toRfc4122()]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        try {
            $this->magicLinkMailer->sendSignInLink($user, $magicLink);
        } catch (TransportExceptionInterface $transportException) {
            // Only an existing account reaches the send, so a 500 here would tell the
            // caller the address is registered: answer neutrally like every other
            // branch. The link is never stored, so the next request makes a fresh one.
            // The class and code only: an SMTP rejection quotes the recipient.
            $this->logger->error('Auth request-link email could not be sent', [
                'user' => $user->getId()->toRfc4122(),
                'error' => $transportException::class,
                'code' => $transportException->getCode(),
            ]);

            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        try {
            $this->magicLinkRepository->save($magicLink);
        } catch (UniqueConstraintViolationException) {
            // Concurrent request already created a link — return neutral response
            return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
        }

        $this->logger->debug('Auth request-link magic link created and sent', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(['message' => $neutralMessage], Response::HTTP_ACCEPTED);
    }
}
