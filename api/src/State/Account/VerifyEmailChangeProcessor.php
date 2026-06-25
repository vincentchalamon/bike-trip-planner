<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\EmailChange;
use App\Entity\EmailChangeToken;
use App\Entity\User;
use App\Repository\EmailChangeTokenRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Verifies an email-change token (#777): checks ownership first, then atomically
 * consumes it (single-use, not expired) and commits the new address.
 *
 * Order matters for security: the token's ownership is verified BEFORE it is
 * consumed, so a valid token belonging to another account is rejected (403)
 * without being burned. An invalid/expired/already-used token is a 422 (the
 * caller is authenticated, so 401 would be wrong). Email uniqueness is enforced
 * at the DB level — if the target address was taken between request and verify,
 * the unique constraint violation is mapped to a 422.
 *
 * @implements ProcessorInterface<EmailChange, JsonResponse>
 */
final readonly class VerifyEmailChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private EmailChangeTokenRepository $emailChangeTokenRepository,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param EmailChange $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        // Look up the token WITHOUT consuming it so ownership can be checked
        // before the single-use consumption is committed.
        $token = $this->emailChangeTokenRepository->findValidByToken($data->token);

        // Missing, expired or already consumed: the user is authenticated, so
        // this is a 422 (unprocessable token), not a 401.
        if (!$token instanceof EmailChangeToken) {
            $this->logger->debug('Email change verify invalid token', ['user' => $user->getId()->toRfc4122()]);

            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.invalid_link', [], 'account'));
        }

        // A valid token belonging to someone else must be rejected WITHOUT being
        // consumed (403): never burn another account's pending change.
        if ($token->getUser()->getId() != $user->getId()) {
            $this->logger->warning('Email change verify ownership mismatch', ['user' => $user->getId()->toRfc4122()]);

            throw new AccessDeniedHttpException($this->translator->trans('email_change.error.invalid_link', [], 'account'));
        }

        // Atomically consume (scoped to this user). Losing the race here means a
        // concurrent request already consumed it — treat as an invalid token.
        if (!$this->emailChangeTokenRepository->consumeByTokenForUser($data->token, $user)) {
            $this->logger->debug('Email change verify token consumed concurrently', ['user' => $user->getId()->toRfc4122()]);

            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.invalid_link', [], 'account'));
        }

        $newEmail = $token->getNewEmail();
        \assert('' !== $newEmail);
        $user->setEmail($newEmail);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The address was claimed by another account between request and verify.
            $this->logger->debug('Email change verify uniqueness violation', ['user' => $user->getId()->toRfc4122()]);

            throw new UnprocessableEntityHttpException($this->translator->trans('email_change.error.email_taken', [], 'account'));
        }

        $this->logger->info('Email changed', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(
            ['email' => $newEmail],
            Response::HTTP_OK,
        );
    }
}
