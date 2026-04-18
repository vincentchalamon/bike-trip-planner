<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AccessRequestHmacService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccessRequestHmacServiceTest extends TestCase
{
    private AccessRequestHmacService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = new AccessRequestHmacService('test-secret-key');
    }

    #[Test]
    public function generatePayloadReturnsExpectedStructure(): void
    {
        $payload = $this->service->generatePayload('test@example.com');

        $this->assertArrayHasKey('email', $payload);
        $this->assertArrayHasKey('expires', $payload);
        $this->assertArrayHasKey('signature', $payload);
        $this->assertSame('test@example.com', $payload['email']);
        $this->assertIsInt($payload['expires']);
        $this->assertIsString($payload['signature']);
        $this->assertNotEmpty($payload['signature']);
    }

    #[Test]
    public function generatePayloadExpiresInFuture(): void
    {
        $payload = $this->service->generatePayload('test@example.com');

        $this->assertGreaterThan(time(), $payload['expires']);
        // Should be approximately 24 hours in the future
        $this->assertGreaterThan(time() + 23 * 3600, $payload['expires']);
        $this->assertLessThanOrEqual(time() + 25 * 3600, $payload['expires']);
    }

    #[Test]
    public function verifyReturnsTrueForValidPayload(): void
    {
        $payload = $this->service->generatePayload('alice@example.com');

        $result = $this->service->verify($payload);

        $this->assertTrue($result);
    }

    #[Test]
    public function verifyReturnsFalseForInvalidSignature(): void
    {
        $payload = $this->service->generatePayload('alice@example.com');
        $payload['signature'] = 'invalidsignature';

        $result = $this->service->verify($payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForExpiredPayload(): void
    {
        $expires = new \DateTimeImmutable('-1 day')->getTimestamp();
        $signature = hash_hmac('sha256', 'alice@example.com|'.$expires, 'test-secret-key');

        $result = $this->service->verify([
            'email' => 'alice@example.com',
            'expires' => (string) $expires,
            'signature' => $signature,
        ]);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForTamperedEmail(): void
    {
        $payload = $this->service->generatePayload('alice@example.com');
        $payload['email'] = 'evil@example.com';

        $result = $this->service->verify($payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForMissingParams(): void
    {
        $this->assertFalse($this->service->verify([]));
        $this->assertFalse($this->service->verify(['email' => 'test@example.com']));
        $this->assertFalse($this->service->verify(['email' => 'test@example.com', 'expires' => time() + 3600]));
    }

    #[Test]
    public function signatureIsDeterministicForSameInput(): void
    {
        $expires = time() + 3600;
        $service = new AccessRequestHmacService('test-secret-key');

        $service->generatePayload('same@example.com');
        // Signatures differ because expires changes each call — test consistency via verify()
        $payload = ['email' => 'same@example.com', 'expires' => $expires, 'signature' => hash_hmac('sha256', 'same@example.com|'.$expires, 'test-secret-key')];

        $this->assertTrue($service->verify($payload));
    }

    #[Test]
    public function differentSecretsProduceDifferentSignatures(): void
    {
        $service1 = new AccessRequestHmacService('secret-one');
        $service2 = new AccessRequestHmacService('secret-two');

        $payload = $service1->generatePayload('test@example.com');

        $this->assertFalse($service2->verify($payload));
    }

    #[Test]
    public function constructorThrowsOnEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ACCESS_REQUEST_HMAC_SECRET must not be empty.');

        new AccessRequestHmacService('');
    }
}
