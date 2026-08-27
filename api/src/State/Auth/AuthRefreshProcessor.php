<?php

declare(strict_types=1);

namespace App\State\Auth;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Auth\RefreshRequest;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rotates a refresh token and issues a new JWT.
 *
 * The refresh token travels in the request body (OAuth-like) and the rotated
 * token is returned in the body too: the API is client-agnostic and the web BFF
 * (step 2) owns the cookie.
 *
 * @implements ProcessorInterface<RefreshRequest, JsonResponse>
 */
final readonly class AuthRefreshProcessor implements ProcessorInterface
{
    /**
     * Seconds a just-rotated token stays usable so a reload race (the client
     * re-sends the pre-rotation token) resolves to its successor instead of a
     * 401. Far longer than any reload round-trip, far shorter than the TTL.
     */
    private const int GRACE_SECONDS = 30;

    public function __construct(
        private RefreshTokenRepository $refreshTokenRepository,
        private EntityManagerInterface $entityManager,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private RefreshTokenEncryptor $encryptor,
    ) {
    }

    /**
     * @param RefreshRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $token = $data->refreshToken;

        $existing = $this->refreshTokenRepository->findValidByToken($token);

        if (!$existing instanceof RefreshToken) {
            // Reuse detection (OAuth BCP): a token that is no longer valid but was
            // already rotated (replaced_by_token set) is being replayed past its
            // 30s grace window — treat it as a leaked/stolen token and revoke the
            // whole family so the attacker's successor (if any) dies too. A token
            // that merely expired without ever rotating is a normal 401.
            $reused = $this->refreshTokenRepository->findAnyByToken($token);
            if ($reused instanceof RefreshToken && null !== $reused->getReplacedByToken()) {
                $this->refreshTokenRepository->removeAllForUser($reused->getUser());
                $this->entityManager->flush();
                $this->logger->warning('Auth refresh token reuse detected — revoked the token family', ['user' => $reused->getUser()->getId()->toRfc4122()]);
            } else {
                $this->logger->debug('Auth refresh invalid token');
            }

            return $this->unauthorized();
        }

        $user = $existing->getUser();

        // A deleted (anonymised) account must never be re-authenticated, even if
        // a refresh token lingered (GDPR erasure is final).
        if ($user->isDeleted()) {
            $this->logger->warning('Auth refresh attempted on a deleted account', ['user' => $user->getId()->toRfc4122()]);

            return $this->unauthorized();
        }

        // Resolve the live successor token. A refresh token is rotated on first
        // use but kept valid for a short grace window: a rapid reload re-sends the
        // pre-rotation token before the client applied the rotation, and that
        // request must resolve to the successor (idempotent) rather than a 401
        // that destroys the session (recette #649).
        $replacedBy = $existing->getReplacedByToken();
        $live = null !== $replacedBy
            ? $this->refreshTokenRepository->findValidByDigest($replacedBy)
            : $this->rotate($existing, $user);

        if (!$live instanceof RefreshToken) {
            // The successor itself fell out of its grace window: genuinely stale.
            $this->logger->debug('Auth refresh successor no longer valid');

            return $this->unauthorized();
        }

        // Recover the plaintext to re-serve: fresh on the just-minted successor,
        // decrypted from the stored ciphertext on the grace-window path.
        $livePlain = $live->getPlainToken() ?? $this->encryptor->decrypt($live->getEncryptedToken());
        if (null === $livePlain) {
            // Ciphertext undecryptable (encryption key rotated): treat as stale.
            $this->logger->warning('Auth refresh successor could not be decrypted');

            return $this->unauthorized();
        }

        $jwt = $this->jwtManager->create($user);

        $this->logger->debug('Auth refresh success', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(['token' => $jwt, 'refresh_token' => $livePlain]);
    }

    /**
     * Rotates the token but KEEPS the old one for a short grace window pointing
     * at its successor, instead of deleting it. A reload race that re-sends the
     * pre-rotation token then resolves to the successor (idempotent) rather than
     * a 401 that destroys the session (recette #649).
     */
    private function rotate(RefreshToken $existing, User $user): ?RefreshToken
    {
        // Successor created in-memory only (createForUser persists, never flushes).
        $successor = $this->refreshTokenRepository->createForUser($user);
        $grace = new \DateTimeImmutable(\sprintf('+%d seconds', self::GRACE_SECONDS));
        $claimed = 0;

        $this->entityManager->wrapInTransaction(function () use ($existing, $successor, $grace, &$claimed): void {
            // Atomic CAS: only the first concurrent caller flips replaced_by_token
            // from NULL — this fences a double-rotation that would otherwise orphan
            // a live 30-day successor (and ensures the INSERT shares the txn).
            $claimed = $this->entityManager->getConnection()->executeStatement(
                'UPDATE refresh_token SET replaced_by_token = :new, expires_at = :grace WHERE id = :id AND replaced_by_token IS NULL AND expires_at > NOW()',
                [
                    'new' => $successor->getTokenDigest(),
                    'grace' => $grace->format('Y-m-d H:i:s'),
                    'id' => $existing->getId()->toRfc4122(),
                ],
            );

            if (1 === $claimed) {
                // Won: mirror the claim onto the managed entity so the successor is
                // flushed and the mapped property is assigned in PHP (PHPStan can't
                // see Doctrine's hydration of replaced_by_token).
                $existing->replaceWith($successor->getTokenDigest(), $grace);
            } else {
                // Lost: drop the pending successor so the flush can't INSERT it.
                $this->entityManager->detach($successor);
            }
        });

        if (1 === $claimed) {
            return $successor;
        }

        // Lost the race: a concurrent request rotated first. Follow its chain.
        $this->entityManager->refresh($existing);
        $replacedBy = $existing->getReplacedByToken();

        return null !== $replacedBy ? $this->refreshTokenRepository->findValidByDigest($replacedBy) : null;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans('auth.error.refresh_invalid', [], 'auth')],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
