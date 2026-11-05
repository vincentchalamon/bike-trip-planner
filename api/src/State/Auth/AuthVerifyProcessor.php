<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\Auth;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use App\Repository\RefreshTokenRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates a magic link token, issues JWT + refresh token.
 *
 * Signs in silently only when the request carries the nonce of the client that asked for
 * the link; otherwise answers 409 with the account email until the user confirms (SEC-15).
 *
 * The refresh token is returned in the response body (OAuth-like), not a cookie:
 * the API is client-agnostic and the web BFF (step 2) owns the cookie.
 *
 * @implements ProcessorInterface<Auth, JsonResponse>
 */
final readonly class AuthVerifyProcessor implements ProcessorInterface
{
    public function __construct(
        private MagicLinkRepository $magicLinkRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param Auth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $magicLink = $this->magicLinkRepository->findConsumableByToken($data->token);

        // A deleted (anonymised) account must never be re-authenticated, even
        // with an otherwise-valid magic link (GDPR erasure is final). Neutral
        // 401 — do not leak that the account existed.
        if (!$magicLink instanceof MagicLink || $magicLink->getUser()->isDeleted()) {
            $this->logger->debug('Auth verify invalid token');

            return $this->invalidLink();
        }

        // A link opened away from the client that asked for it (another browser, another
        // device, or a link someone else requested and forwarded) must not switch the
        // session silently (SEC-15). Nothing is consumed: the client shows the account the
        // link opens and replays with `confirm` if the user goes on.
        if (!$data->confirm && !$magicLink->isBoundTo($data->nonce)) {
            $this->logger->debug('Auth verify needs confirmation', ['user' => $magicLink->getUser()->getId()->toRfc4122()]);

            return new JsonResponse(['email' => $magicLink->getUser()->getEmail()], Response::HTTP_CONFLICT);
        }

        $user = $this->magicLinkRepository->consumeByToken($data->token);

        if (!$user instanceof User) {
            $this->logger->debug('Auth verify token consumed concurrently');

            return $this->invalidLink();
        }

        $jwt = $this->jwtManager->create($user);

        $refreshToken = $this->refreshTokenRepository->createForUser($user);

        // Plaintext is available on the freshly minted token (stored encrypted).
        $plainToken = $refreshToken->getPlainToken();
        \assert(null !== $plainToken);

        $this->logger->debug('Auth verify token verified', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(['token' => $jwt, 'refresh_token' => $plainToken]);
    }

    private function invalidLink(): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans('auth.error.invalid_link', [], 'auth')],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
