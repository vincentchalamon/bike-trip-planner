<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless HMAC-based signed URL service for access request email verification.
 *
 * The signature is computed as: hash_hmac('sha256', email + '|' + expires, ACCESS_REQUEST_HMAC_SECRET)
 * The '|' separator prevents ambiguity (e.g. "a@b.com" + "1234" vs "a@b.com1" + "234").
 * No token is stored in the database — the signature IS the proof of authenticity.
 */
final readonly class AccessRequestHmacService
{
    private const int TTL_HOURS = 24;

    public function __construct(
        #[Autowire(env: 'ACCESS_REQUEST_HMAC_SECRET')]
        private string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('ACCESS_REQUEST_HMAC_SECRET must not be empty.');
        }
    }

    /**
     * Generates a signed verification URL payload (query parameters).
     *
     * @return array{email: string, expires: int, signature: string}
     */
    public function generatePayload(string $email): array
    {
        $expires = new \DateTimeImmutable(\sprintf('+%d hours', self::TTL_HOURS))->getTimestamp();
        $signature = $this->computeSignature($email, $expires);

        return [
            'email' => $email,
            'expires' => $expires,
            'signature' => $signature,
        ];
    }

    /**
     * Verifies the HMAC signature and expiration.
     *
     * @param array{email?: mixed, expires?: mixed, signature?: mixed} $params
     */
    public function verify(array $params): bool
    {
        $email = $params['email'] ?? null;
        $expires = $params['expires'] ?? null;
        $signature = $params['signature'] ?? null;

        if (!\is_string($email) || !\is_string($signature) || !\is_numeric($expires)) {
            return false;
        }

        $expiresInt = (int) $expires;

        if (time() > $expiresInt) {
            return false;
        }

        $expected = $this->computeSignature($email, $expiresInt);

        return hash_equals($expected, $signature);
    }

    private function computeSignature(string $email, int $expires): string
    {
        return hash_hmac('sha256', $email.'|'.$expires, $this->secret);
    }
}
