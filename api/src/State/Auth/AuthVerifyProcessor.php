<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\AuthVerify;
use App\Entity\User;
use App\Security\AuthCookies;
use App\Security\MagicLinkManager;
use App\Security\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates a magic link token, issues JWT + refresh token.
 *
 * For Capacitor clients (Origin: capacitor://), the refresh token is also included in the response body.
 *
 * @implements ProcessorInterface<AuthVerify, JsonResponse>
 */
final readonly class AuthVerifyProcessor implements ProcessorInterface
{
    public function __construct(
        private MagicLinkManager $magicLinkManager,
        private RefreshTokenManager $refreshTokenManager,
        private JWTTokenManagerInterface $jwtManager,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param AuthVerify $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->magicLinkManager->verify($data->token);

        if (!$user instanceof User) {
            $this->logger->debug('Auth verify invalid token');

            return new JsonResponse(
                ['error' => $this->translator->trans('auth.error.invalid_link', [], 'auth')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenManager->createRefreshToken($user);
        $isCapacitor = $this->isCapacitorRequest();

        $this->logger->debug('Auth verify token verified', ['user' => $user->getEmail()]);

        $responseData = ['token' => $jwt];
        if ($isCapacitor) {
            $responseData['refresh_token'] = $refreshToken->getToken();
        }

        $response = new JsonResponse($responseData);
        $this->setRefreshTokenCookie($response, $refreshToken->getToken(), $refreshToken->getExpiresAt());

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
        $cookie = Cookie::create(AuthCookies::REFRESH_TOKEN)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');

        $response->headers->setCookie($cookie);
    }
}
