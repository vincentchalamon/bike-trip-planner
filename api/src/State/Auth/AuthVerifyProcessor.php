<?php

declare(strict_types=1);

namespace App\State\Auth;

use App\Entity\RefreshToken;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use App\Repository\RefreshTokenRepository;
use App\Service\Auth\AuthResponseHelper;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates a magic link token, issues JWT + refresh token.
 *
 * For Capacitor clients (Origin: capacitor://), the refresh token is also included in the response body.
 *
 * @implements ProcessorInterface<Auth, JsonResponse>
 */
final readonly class AuthVerifyProcessor implements ProcessorInterface
{
    use AuthResponseHelper;

    public function __construct(
        private MagicLinkRepository $magicLinkRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private EntityManagerInterface $entityManager,
        private JWTTokenManagerInterface $jwtManager,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param Auth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        // Wrap in transaction: consumeByToken (DELETE) + createForUser (persist) must be atomic
        $user = null;
        $refreshToken = null;

        $this->entityManager->wrapInTransaction(function () use ($data, &$user, &$refreshToken): void {
            $user = $this->magicLinkRepository->consumeByToken($data->token);

            if ($user instanceof User) {
                $refreshToken = $this->refreshTokenRepository->createForUser($user);
            }
        });

        if (!$user instanceof User) {
            $this->logger->debug('Auth verify invalid token');

            return new JsonResponse(
                ['error' => $this->translator->trans('auth.error.invalid_link', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        \assert($refreshToken instanceof RefreshToken);

        $jwt = $this->jwtManager->create($user);

        $request = $this->requestStack->getCurrentRequest();
        $isCapacitor = $this->isCapacitorRequest($request);

        $this->logger->debug('Auth verify token verified', ['user' => $user->getEmail()]);

        $responseData = ['token' => $jwt];
        if ($isCapacitor) {
            $responseData['refresh_token'] = $refreshToken->getToken();
        }

        $response = new JsonResponse($responseData);
        $response->headers->setCookie(
            $this->createRefreshTokenCookie($refreshToken->getToken(), $refreshToken->getExpiresAt()),
        );

        return $response;
    }
}
