<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Auth\AuthSession;
use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use App\Security\AuthCookies;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Read-only session introspection (recette #649 #8, ADR-047).
 *
 * Validates the refresh_token cookie WITHOUT rotating it (unlike
 * {@see AuthRefreshProcessor}): it reports whether a live, non-deleted user
 * session exists so the Next.js RSC gate can decide landing vs dashboard before
 * render. It must stay idempotent — no rotation, no Set-Cookie, no JWT issued —
 * so it is safe to call on every server render / deep-link. Web cookie transport
 * only; the mobile static build keeps the client-side bootstrap.
 *
 * @implements ProviderInterface<AuthSession>
 */
final readonly class AuthSessionProvider implements ProviderInterface
{
    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AuthSession
    {
        $token = $this->requestStack->getCurrentRequest()?->cookies->get(AuthCookies::REFRESH_TOKEN);

        if (null === $token || '' === $token) {
            return new AuthSession();
        }

        $existing = $this->refreshTokenRepository->findValidByToken($token);

        if (!$existing instanceof RefreshToken) {
            return new AuthSession();
        }

        $user = $existing->getUser();

        // A deleted (anonymised) account must never resolve as authenticated —
        // GDPR erasure is final. Mirrors AuthRefreshProcessor's guard.
        if ($user->isDeleted()) {
            $this->logger->warning('Auth session on a deleted account', ['user' => $user->getId()->toRfc4122()]);

            return new AuthSession();
        }

        return new AuthSession(
            authenticated: true,
            userId: $user->getId()->toRfc4122(),
            email: $user->getEmail(),
        );
    }
}
