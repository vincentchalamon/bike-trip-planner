<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\Account;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\MagicLinkRepository;
use App\Repository\RefreshTokenRepository;
use App\Security\AuthCookies;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GDPR right to erasure: anonymises the current user's account.
 *
 * Soft-deletes the account (stamps deletedAt), irreversibly anonymises the
 * email to break the PII link, purges every trip (and, via cascade, their
 * stages/preferences), and revokes all refresh tokens. The trips carry the
 * per-trip preferences (pacing, accommodation types…), so removing them also
 * erases those preferences.
 *
 * @implements ProcessorInterface<Account, Response>
 */
final readonly class AccountDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RefreshTokenRepository $refreshTokenRepository,
        private MagicLinkRepository $magicLinkRepository,
        private AccessRequestRepository $accessRequestRepository,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Account $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        // Capture the email before anonymisation so we can purge the standalone
        // access_request rows (email PII, no user FK) that hold it.
        $email = $user->getEmail();

        $this->entityManager->wrapInTransaction(function () use ($user, $email): void {
            // Purge trips (cascades to stages, chat messages and shares via FK
            // ON DELETE CASCADE) which also removes the per-trip preferences.
            $this->entityManager->createQueryBuilder()
                ->delete(TripRequest::class, 't')
                ->where('t.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->execute();

            // Revoke every refresh token so lingering sessions cannot be reused.
            $this->refreshTokenRepository->removeAllForUser($user);

            // Purge magic links: the soft-delete below does not trigger the FK
            // ON DELETE CASCADE, so a lingering valid link could otherwise still
            // authenticate the (now anonymised) account.
            $this->magicLinkRepository->removeAllForUser($user);

            // Purge early-access requests holding the email/IP PII (standalone
            // table, no user FK).
            $this->accessRequestRepository->removeAllForEmail($email);

            // Soft-delete + irreversible PII anonymisation.
            $user->anonymize();
        });

        $this->logger->info('Account deleted (GDPR erasure)', ['user' => $user->getId()->toRfc4122()]);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie(AuthCookies::REFRESH_TOKEN, '/', null, true, true, 'strict');

        return $response;
    }
}
