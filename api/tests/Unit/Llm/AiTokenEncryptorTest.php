<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\AiTokenEncryptor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiTokenEncryptorTest extends TestCase
{
    #[Test]
    public function encryptsAndDecryptsRoundTrip(): void
    {
        $encryptor = new AiTokenEncryptor('a-secret-key');

        $cipher = $encryptor->encrypt('sk-ant-secret-token');

        self::assertNotSame('sk-ant-secret-token', $cipher, 'the token is not stored in clear');
        self::assertSame('sk-ant-secret-token', $encryptor->decrypt($cipher));
    }

    #[Test]
    public function producesADifferentCiphertextEachTime(): void
    {
        // Random nonce per call: same input must not yield the same blob.
        $encryptor = new AiTokenEncryptor('a-secret-key');

        self::assertNotSame($encryptor->encrypt('token'), $encryptor->encrypt('token'));
    }

    #[Test]
    public function returnsNullForAMalformedBlob(): void
    {
        $encryptor = new AiTokenEncryptor('a-secret-key');

        self::assertNull($encryptor->decrypt('not-base64-or-too-short'));
        self::assertNull($encryptor->decrypt(''));
    }

    #[Test]
    public function returnsNullWhenDecryptingUnderADifferentKey(): void
    {
        // Key rotation: previously stored tokens become undecryptable (by design).
        $cipher = new AiTokenEncryptor('original-key')->encrypt('token');

        self::assertNull(new AiTokenEncryptor('rotated-key')->decrypt($cipher));
    }

    #[Test]
    public function rejectsAnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiTokenEncryptor('');
    }
}
