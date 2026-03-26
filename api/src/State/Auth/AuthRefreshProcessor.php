<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
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
        $token = $request?->cookies->get(Auth::REFRESH_TOKEN_COOKIE);
        $isCapacitor = $this->isCapacitorRequest();

        // Capacitor sends refresh token in body
        if (null === $token && $isCapacitor && $request instanceof \Symfony\Component\HttpFoundation\Request) {
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

        if (!$existing instanceof \App\Entity\RefreshToken) {
            $this->logger->debug('Auth refresh invalid token');

            $response = new JsonResponse(
                ['error' => $this->translator->trans('auth.error.refresh_invalid', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
            $response->headers->clearCookie(Auth::REFRESH_TOKEN_COOKIE, '/', null, true, true, 'strict');

            return $response;
        }

        // Atomically expire the token to prevent TOCTOU race conditions
        $affected = $this->refreshTokenRepository->atomicExpire($existing);
        if (0 === $affected) {
            $this->logger->debug('Auth refresh token already consumed (TOCTOU)');

            return new JsonResponse(
                ['error' => $this->translator->trans('auth.error.refresh_invalid', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $user = $existing->getUser();
        $this->entityManager->remove($existing);
        $newRefreshToken = $this->refreshTokenRepository->createForUser($user);
        $this->entityManager->flush();

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

    private function isCapacitorRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $origin = $request?->headers->get('Origin', '') ?? '';

        return str_starts_with($origin, 'capacitor://');
    }

    private function setRefreshTokenCookie(JsonResponse $response, string $token, \DateTimeImmutable $expiresAt): void
    {
        $cookie = Cookie::create(Auth::REFRESH_TOKEN_COOKIE)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');

        $response->headers->setCookie($cookie);
    }
}
