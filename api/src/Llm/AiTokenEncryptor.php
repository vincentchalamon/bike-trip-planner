<?php

declare(strict_types=1);

namespace App\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric, reversible encryption of a user's AI provider API token at rest
 * (ADR-042). The token is a long-lived paid credential, so it is never stored or
 * logged in clear: callers encrypt before persisting and decrypt only at call
 * time to set the provider Authorization header.
 *
 * Uses libsodium's authenticated `crypto_secretbox` (XSalsa20-Poly1305) with a
 * fresh random nonce per value (nonce is prepended to the ciphertext), so the
 * same token yields different stored blobs and tampering is detected. The key is
 * derived from a dedicated `AI_TOKEN_ENC_KEY` (not APP_SECRET, to decouple
 * rotation) via a generic hash, so any-length secret yields a valid 32-byte key.
 *
 * Rotating the key makes existing stored tokens undecryptable (decrypt() returns
 * null) — by design; the user simply re-enters their token.
 */
final readonly class AiTokenEncryptor
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'app.ai_token_enc_key')]
        string $key,
    ) {
        if ('' === $key) {
            throw new \InvalidArgumentException('AI_TOKEN_ENC_KEY must not be empty.');
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
}
