<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\RefreshTokenEncryptor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefreshTokenEncryptorTest extends TestCase
{
    #[Test]
    public function encryptsAndDecryptsRoundTrip(): void
    {
        $encryptor = new RefreshTokenEncryptor('a-secret-key');

        $cipher = $encryptor->encrypt('refresh-token-plaintext');

        self::assertNotSame('refresh-token-plaintext', $cipher, 'the token is not stored in clear');
        self::assertSame('refresh-token-plaintext', $encryptor->decrypt($cipher));
    }

    #[Test]
    public function producesADifferentCiphertextEachTime(): void
    {
        // Random nonce per call: same input must not yield the same blob.
        $encryptor = new RefreshTokenEncryptor('a-secret-key');

        self::assertNotSame($encryptor->encrypt('token'), $encryptor->encrypt('token'));
    }

    #[Test]
    public function returnsNullForAMalformedBlob(): void
    {
        $encryptor = new RefreshTokenEncryptor('a-secret-key');

        self::assertNull($encryptor->decrypt('not-base64-or-too-short'));
        self::assertNull($encryptor->decrypt(''));
    }

    #[Test]
    public function returnsNullWhenDecryptingUnderADifferentKey(): void
    {
        // Key rotation: previously stored tokens become undecryptable (by design).
        $cipher = new RefreshTokenEncryptor('original-key')->encrypt('token');

        self::assertNull(new RefreshTokenEncryptor('rotated-key')->decrypt($cipher));
    }

    #[Test]
    public function rejectsAnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefreshTokenEncryptor('');
    }

    #[Test]
    public function digestIsDeterministicAndDoesNotRevealThePlaintext(): void
    {
        self::assertSame(
            RefreshTokenEncryptor::digest('token'),
            RefreshTokenEncryptor::digest('token'),
        );
        self::assertNotSame('token', RefreshTokenEncryptor::digest('token'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', RefreshTokenEncryptor::digest('token'));
    }
}
