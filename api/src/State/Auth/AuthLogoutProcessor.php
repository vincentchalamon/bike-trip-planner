<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Security\AuthCookies;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes all refresh tokens for the current user and clears the cookie.
 *
 * @implements ProcessorInterface<Auth, Response>
 */
final readonly class AuthLogoutProcessor implements ProcessorInterface
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private EntityManagerInterface $entityManager,
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
            $this->entityManager->flush();
            $this->logger->debug('Auth logout user logged out', ['user' => $user->getEmail()]);
        }

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie(AuthCookies::REFRESH_TOKEN, '/', null, true, true, 'strict');

        return $response;
    }
}
