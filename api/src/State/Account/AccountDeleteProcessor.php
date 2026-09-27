<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\Account;
use App\Entity\User;
use App\Security\AccountEraser;
use App\Security\AuthCookies;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GDPR right to erasure: anonymises the current user's account.
 *
 * What is destroyed, and in which order, lives in {@see AccountEraser} — the order spans the
 * whole sequence (one revocation has to precede the anonymisation), so it belongs to one unit
 * rather than to a processor that also speaks HTTP (#1309). This is the HTTP: resolve the
 * caller, erase, log, clear the cookie, 204.
 *
 * @implements ProcessorInterface<Account, Response>
 */
final readonly class AccountDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private AccountEraser $eraser,
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

        $this->eraser->erase($user);

        $this->logger->info('Account deleted (GDPR erasure)', ['user' => $user->getId()->toRfc4122()]);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie(AuthCookies::REFRESH_TOKEN, '/', null, true, true, 'strict');

        return $response;
    }
}
