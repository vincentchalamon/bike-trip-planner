<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric, reversible encryption of refresh tokens at rest (SEC-003).
 *
 * In a passwordless system the refresh token *is* the credential (a 30-day
 * bearer secret): storing it in clear means one database read (backup leak,
 * replica, SQL injection, DBA/insider) yields tokens usable to forge JWTs. The
 * token must still be recoverable — the rotation grace window re-serves the
 * successor token loaded from the row (#649) — so a one-way hash is unusable
 * here; the row is looked up by a deterministic `token_digest` (sha256) while
 * the token itself is stored encrypted.
 *
 * Uses libsodium's authenticated `crypto_secretbox` (XSalsa20-Poly1305) with a
 * fresh random nonce per value (prepended to the ciphertext). Reuses the app's
 * token-encryption key (`AI_TOKEN_ENC_KEY`) rather than introducing a new
 * mandatory prod secret; the prod entrypoint now refuses to boot when it is unset
 * or still the committed dev default (SEC-003 fail-closed guard), so the key can
 * never silently fall back to a public value. Rotating it invalidates both AI
 * tokens and refresh sessions (users simply re-login).
 * Kept separate from AiTokenEncryptor so the auth subsystem does not depend on
 * the AI module.
 */
final readonly class RefreshTokenEncryptor
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'app.ai_token_enc_key')]
        #[\SensitiveParameter]
        string $key,
    ) {
        if ('' === $key) {
            throw new \InvalidArgumentException('Token encryption key must not be empty.');
        }

        $this->key = sodium_crypto_generichash($key, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * Returns null when the blob is malformed, tampered, or encrypted under a
     * different (rotated) key — never throws.
     */
    public function decrypt(string $encrypted): ?string
    {
        $decoded = base64_decode($encrypted, true);
        if (false === $decoded || \strlen($decoded) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        return false === $plaintext ? null : $plaintext;
    }

    public static function digest(#[\SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
