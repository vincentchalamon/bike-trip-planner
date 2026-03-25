<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\AuthLogout;
use App\Entity\User;
use App\Security\MagicLinkManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes all refresh tokens for the current user and clears the cookie.
 *
 * @implements ProcessorInterface<AuthLogout, Response>
 */
final readonly class AuthLogoutProcessor implements ProcessorInterface
{
    private const string REFRESH_TOKEN_COOKIE = 'refresh_token';

    public function __construct(
        private MagicLinkManager $magicLinkManager,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param AuthLogout $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $this->magicLinkManager->revokeAllRefreshTokens($user);
            $this->logger->debug('Auth logout user logged out', ['user' => $user->getEmail()]);
        }

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie(self::REFRESH_TOKEN_COOKIE, '/', null, true, true, 'strict');

        return $response;
    }
}
