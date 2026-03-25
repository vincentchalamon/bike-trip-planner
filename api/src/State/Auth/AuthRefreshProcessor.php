<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\AuthRefresh;
use App\Entity\RefreshToken;
use App\Security\MagicLinkManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotates a refresh token and issues a new JWT.
 *
 * Reads the refresh token from cookie or request body (Capacitor).
 *
 * @implements ProcessorInterface<AuthRefresh, JsonResponse>
 */
final readonly class AuthRefreshProcessor implements ProcessorInterface
{
    private const string REFRESH_TOKEN_COOKIE = 'refresh_token';

    public function __construct(
        private MagicLinkManager $magicLinkManager,
        private JWTTokenManagerInterface $jwtManager,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param AuthRefresh $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $token = $request?->cookies->get(self::REFRESH_TOKEN_COOKIE);
        $isCapacitor = $this->isCapacitorRequest();

        // Capacitor sends refresh token in body
        if (null === $token && $isCapacitor && null !== $request) {
            $body = $request->toArray();
            $token = $body['refresh_token'] ?? null;
        }

        if (null === $token || '' === $token) {
            return new JsonResponse(
                ['error' => 'Refresh token manquant.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $newRefreshToken = $this->magicLinkManager->rotateRefreshToken($token);

        if (!$newRefreshToken instanceof RefreshToken) {
            $this->logger->debug('Auth refresh invalid token');

            $response = new JsonResponse(
                ['error' => 'Refresh token invalide ou expiré.'],
                Response::HTTP_UNAUTHORIZED,
            );
            $response->headers->clearCookie(self::REFRESH_TOKEN_COOKIE, '/', null, true, true, 'strict');

            return $response;
        }

        $user = $newRefreshToken->getUser();
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
        $cookie = Cookie::create(self::REFRESH_TOKEN_COOKIE)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');

        $response->headers->setCookie($cookie);
    }
}
