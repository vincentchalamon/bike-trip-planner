<?php

declare(strict_types=1);

namespace App\State\Auth;

use Symfony\Component\HttpFoundation\Request;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use App\Security\AuthCookies;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rotates a refresh token and issues a new JWT.
 *
 * Reads the refresh token from cookie or request body (Capacitor).
 *
 * @implements ProcessorInterface<Auth, JsonResponse>
 */
final readonly class AuthRefreshProcessor implements ProcessorInterface
{
    use AuthResponseHelper;

    public function __construct(
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
        $request = $this->requestStack->getCurrentRequest();
        $token = $request?->cookies->get(AuthCookies::REFRESH_TOKEN);
        $isCapacitor = $this->isCapacitorRequest();

        // Capacitor sends refresh token in body
        if (null === $token && $isCapacitor && $request instanceof Request) {
            $body = $request->toArray();
            $token = $body['refresh_token'] ?? null;
        }

        if (null === $token || '' === $token) {
            return new JsonResponse(
                ['error' => $this->translator->trans('auth.error.refresh_missing', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $existing = $this->refreshTokenRepository->findValidByToken($token);

        if (!$existing instanceof RefreshToken) {
            $this->logger->debug('Auth refresh invalid token');

            $response = new JsonResponse(
                ['error' => $this->translator->trans('auth.error.refresh_invalid', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
            $response->headers->clearCookie(AuthCookies::REFRESH_TOKEN, '/', null, true, true, 'strict');

            return $response;
        }

        // Atomic expire + rotate in a single transaction to prevent TOCTOU race
        // and ensure no token is lost if the flush fails.
        $user = $existing->getUser();
        $expiredRows = 0;
        $newRefreshToken = null;

        $this->entityManager->wrapInTransaction(function () use ($existing, $user, &$expiredRows, &$newRefreshToken): void {
            $expiredRows = $this->entityManager->getConnection()->executeStatement(
                "UPDATE refresh_token SET expires_at = '1970-01-01 00:00:00' WHERE id = :id AND expires_at > NOW()",
                ['id' => $existing->getId()->toRfc4122()],
            );

            if (0 === $expiredRows) {
                return;
            }

            $this->entityManager->remove($existing);
            $newRefreshToken = $this->refreshTokenRepository->createForUser($user);
        });

        if (0 === $expiredRows) {
            $this->logger->debug('Auth refresh token already consumed (race)');

            return new JsonResponse(
                ['error' => $this->translator->trans('auth.error.refresh_invalid', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        \assert($newRefreshToken instanceof RefreshToken);

        $jwt = $this->jwtManager->create($user);

        $this->logger->debug('Auth refresh success', ['user' => $user->getEmail()]);

        $responseData = ['token' => $jwt];
        if ($isCapacitor) {
            $responseData['refresh_token'] = $newRefreshToken->getToken();
        }

        $response = new JsonResponse($responseData);
        $this->setRefreshTokenCookie($response, $newRefreshToken->getToken(), $newRefreshToken->getExpiresAt());

        return $response;
    }

    private function getRequestStack(): RequestStack
    {
        return $this->requestStack;
    }
}
