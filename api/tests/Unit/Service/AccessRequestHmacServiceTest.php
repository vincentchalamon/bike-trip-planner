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
        $payload = $this->service->generatePayload('0199a1b2-0000-7000-8000-000000000001');

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('expires', $payload);
        $this->assertArrayHasKey('signature', $payload);
        $this->assertSame('0199a1b2-0000-7000-8000-000000000001', $payload['id']);
        $this->assertIsInt($payload['expires']);
        $this->assertIsString($payload['signature']);
        $this->assertNotEmpty($payload['signature']);
    }

    #[Test]
    public function generatePayloadExpiresInFuture(): void
    {
        $payload = $this->service->generatePayload('0199a1b2-0000-7000-8000-000000000001');

        $this->assertGreaterThan(time(), $payload['expires']);
        // Should be approximately 24 hours in the future
        $this->assertGreaterThan(time() + 23 * 3600, $payload['expires']);
        $this->assertLessThanOrEqual(time() + 25 * 3600, $payload['expires']);
    }

    #[Test]
    public function verifyReturnsTrueForValidPayload(): void
    {
        $payload = $this->service->generatePayload('0199a1b2-0000-7000-8000-00000000000a');

        $result = $this->service->verify($payload);

        $this->assertTrue($result);
    }

    #[Test]
    public function verifyReturnsFalseForInvalidSignature(): void
    {
        $payload = $this->service->generatePayload('0199a1b2-0000-7000-8000-00000000000a');
        $payload['signature'] = 'invalidsignature';

        $result = $this->service->verify($payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForExpiredPayload(): void
    {
        $expires = new \DateTimeImmutable('-1 day')->getTimestamp();
        $signature = hash_hmac('sha256', '0199a1b2-0000-7000-8000-00000000000a|'.$expires, 'test-secret-key');

        $result = $this->service->verify([
            'id' => '0199a1b2-0000-7000-8000-00000000000a',
            'expires' => (string) $expires,
            'signature' => $signature,
        ]);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForTamperedId(): void
    {
        $payload = $this->service->generatePayload('0199a1b2-0000-7000-8000-00000000000a');
        $payload['id'] = '0199a1b2-0000-7000-8000-00000000000e';

        $result = $this->service->verify($payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForMissingParams(): void
    {
        $this->assertFalse($this->service->verify([]));
        $this->assertFalse($this->service->verify(['id' => '0199a1b2-0000-7000-8000-000000000001']));
        $this->assertFalse($this->service->verify(['id' => '0199a1b2-0000-7000-8000-000000000001', 'expires' => time() + 3600]));
    }

    #[Test]
    public function signatureIsDeterministicForSameInput(): void
    {
        $expires = time() + 3600;
        $service = new AccessRequestHmacService('test-secret-key');

        $service->generatePayload('0199a1b2-0000-7000-8000-00000000000b');
        // Signatures differ because expires changes each call — test consistency via verify()
        $payload = ['id' => '0199a1b2-0000-7000-8000-00000000000b', 'expires' => $expires, 'signature' => hash_hmac('sha256', '0199a1b2-0000-7000-8000-00000000000b|'.$expires, 'test-secret-key')];

        $this->assertTrue($service->verify($payload));
    }

    #[Test]
    public function differentSecretsProduceDifferentSignatures(): void
    {
        $service1 = new AccessRequestHmacService('secret-one');
        $service2 = new AccessRequestHmacService('secret-two');

        $payload = $service1->generatePayload('0199a1b2-0000-7000-8000-000000000001');

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
