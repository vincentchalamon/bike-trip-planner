<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless HMAC-based signed link service for access request email verification.
 *
 * The signature is computed as: hash_hmac('sha256', id + '|' + expires, ACCESS_REQUEST_HMAC_SECRET),
 * where id is the access request's UUID. It signs the id rather than the address so the
 * link never carries the address: links end up in logs, histories and error reports, and
 * an address is personal data where the id means nothing without the database.
 * The '|' separator prevents ambiguity between the id and the expiry.
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
     * Generates the signed payload the verification link carries.
     *
     * @return array{id: string, expires: int, signature: string}
     */
    public function generatePayload(string $id): array
    {
        $expires = new \DateTimeImmutable(\sprintf('+%d hours', self::TTL_HOURS))->getTimestamp();
        $signature = $this->computeSignature($id, $expires);

        return [
            'id' => $id,
            'expires' => $expires,
            'signature' => $signature,
        ];
    }

    /**
     * Verifies the HMAC signature and expiration.
     *
     * @param array{id?: mixed, expires?: mixed, signature?: mixed} $params
     */
    public function verify(array $params): bool
    {
        $id = $params['id'] ?? null;
        $expires = $params['expires'] ?? null;
        $signature = $params['signature'] ?? null;

        if (!\is_string($id) || !\is_string($signature) || !\is_numeric($expires)) {
            return false;
        }

        $expiresInt = (int) $expires;

        if (time() > $expiresInt) {
            return false;
        }

        $expected = $this->computeSignature($id, $expiresInt);

        return hash_equals($expected, $signature);
    }

    private function computeSignature(string $id, int $expires): string
    {
        return hash_hmac('sha256', $id.'|'.$expires, $this->secret);
    }
}
