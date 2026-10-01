<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes all refresh tokens for the current user.
 *
 * The API does not own a cookie any more (the web BFF clears it in step 2).
 *
 * @implements ProcessorInterface<Auth, Response>
 */
final readonly class AuthLogoutProcessor implements ProcessorInterface
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Auth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $this->refreshTokenRepository->removeAllForUser($user);
            $this->logger->debug('Auth logout user logged out', ['user' => $user->getId()->toRfc4122()]);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
