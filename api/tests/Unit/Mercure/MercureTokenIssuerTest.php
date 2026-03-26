<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\Mercure\MercureTokenIssuer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MercureTokenIssuerTest extends TestCase
{
    private MercureTokenIssuer $issuer;

    #[\Override]
    protected function setUp(): void
    {
        $this->issuer = new MercureTokenIssuer('test-mercure-secret-key');
    }

    #[Test]
    public function generateSubscriberTokenReturnsValidJwt(): void
    {
        $token = $this->issuer->generateSubscriberToken('trip-uuid-1234');

        // JWT format: header.payload.signature
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('mercure', $payload);
        self::assertSame(['/trips/trip-uuid-1234'], $payload['mercure']['subscribe']);
    }

    #[Test]
    public function generateSubscriberTokenIncludesExpiry(): void
    {
        $before = time();
        $token = $this->issuer->generateSubscriberToken('trip-uuid-1234');
        $after = time();

        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        self::assertIsArray($payload);
        self::assertArrayHasKey('exp', $payload);
        self::assertGreaterThanOrEqual($before + 3600, $payload['exp']);
        self::assertLessThanOrEqual($after + 3600, $payload['exp']);
    }

    #[Test]
    public function createSubscriberCookieReturnsHttpOnlyCookie(): void
    {
        $token = $this->issuer->generateSubscriberToken('trip-uuid-1234');
        $cookie = $this->issuer->createSubscriberCookie($token);

        self::assertSame('mercureAuthorization', $cookie->getName());
        self::assertSame($token, $cookie->getValue());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', $cookie->getSameSite());
    }

    #[Test]
    public function differentTripIdProducesDifferentTokens(): void
    {
        $token1 = $this->issuer->generateSubscriberToken('trip-aaa');
        $token2 = $this->issuer->generateSubscriberToken('trip-bbb');

        self::assertNotSame($token1, $token2);
    }
}
