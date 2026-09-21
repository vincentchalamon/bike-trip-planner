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
        $this->issuer = new MercureTokenIssuer(TestHubFactory::create());
    }

    #[Test]
    public function generateSubscriberTokenGrantsSubscribeOnTheTripTopicOnly(): void
    {
        $payload = $this->decode($this->issuer->generateSubscriberToken('trip-uuid-1234'));

        self::assertSame([
            [
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe'],
                'topics' => [['match' => '/trips/trip-uuid-1234']],
            ],
        ], $payload['authorization_details']);
    }

    #[Test]
    public function generateSubscriberTokenIsAnRfc9068AccessToken(): void
    {
        $token = $this->issuer->generateSubscriberToken('trip-uuid-1234');
        $header = $this->decodeSegment($token, 0);
        $payload = $this->decode($token);

        self::assertSame('at+jwt', $header['typ']);
        self::assertSame(TestHubFactory::ISSUER, $payload['iss']);
        self::assertSame(TestHubFactory::AUDIENCE, $payload['aud']);
        self::assertArrayHasKey('sub', $payload);
        self::assertArrayHasKey('client_id', $payload);
    }

    #[Test]
    public function generateSubscriberTokenIncludesExpiry(): void
    {
        $before = time();
        $exp = $this->expiry($this->issuer->generateSubscriberToken('trip-uuid-1234'));
        $after = time();

        self::assertGreaterThanOrEqual($before + 3600, $exp);
        self::assertLessThanOrEqual($after + 3601, $exp);
    }

    #[Test]
    public function generateSubscriptionsTokenScopesSubscribeToSubscriptionsApiAndTripTopic(): void
    {
        $details = $this->decode($this->issuer->generateSubscriptionsToken('trip-uuid-1234'))['authorization_details'] ?? null;
        self::assertIsArray($details);
        self::assertArrayHasKey(0, $details);
        self::assertIsArray($details[0]);

        self::assertSame([
            ['match' => '/trips/trip-uuid-1234'],
            ['match' => '/.well-known/mercure/subscriptions/*', 'match_type' => 'urlpattern'],
        ], $details[0]['topics'] ?? null);
    }

    #[Test]
    public function generateSubscriptionsTokenExpiresInAboutSixtySeconds(): void
    {
        $before = time();
        $exp = $this->expiry($this->issuer->generateSubscriptionsToken('trip-uuid-1234'));
        $after = time();

        self::assertGreaterThanOrEqual($before + 60, $exp);
        self::assertLessThanOrEqual($after + 61, $exp);
    }

    #[Test]
    public function createSubscriberCookieReturnsHttpOnlyCookie(): void
    {
        $token = $this->issuer->generateSubscriberToken('trip-uuid-1234');
        $cookie = $this->issuer->createSubscriberCookie($token);

        self::assertSame('__Secure-mercure_access_token', $cookie->getName());
        self::assertSame($token, $cookie->getValue());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        // Load-bearing: browsers reject a `__Secure-` cookie that is not Secure.
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', $cookie->getSameSite());
    }

    #[Test]
    public function differentTripIdProducesDifferentTokens(): void
    {
        self::assertNotSame(
            $this->issuer->generateSubscriberToken('trip-aaa'),
            $this->issuer->generateSubscriberToken('trip-bbb'),
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $token): array
    {
        return $this->decodeSegment($token, 1);
    }

    /** @return array<string, mixed> */
    private function decodeSegment(string $token, int $index): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        $raw = base64_decode(strtr($parts[$index], '-_', '+/'), true);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        $claims = [];
        foreach ($decoded as $name => $value) {
            self::assertIsString($name);
            $claims[$name] = $value;
        }

        return $claims;
    }

    private function expiry(string $token): int
    {
        // lcobucci/jwt writes `exp` with sub-second precision, so the claim comes
        // back as a float rather than an int.
        $exp = $this->decode($token)['exp'] ?? null;
        self::assertIsNumeric($exp);

        return (int) $exp;
    }
}
