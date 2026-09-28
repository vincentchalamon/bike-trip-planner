<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Tests IP-based rate limiting on POST /access-requests.
 *
 * The rate limiter is configured to allow 3 requests per hour per IP.
 * We invoke the limiter service directly to ensure state persistence across consume() calls
 * without depending on HTTP kernel reboots (which reset the array-backed cache pool in test env).
 */
#[ResetDatabase]
final class AccessRequestThrottlingTest extends ApiTestCase
{
    /**
     * Verifies that after exactly 3 requests, the 4th is rate-limited.
     * This single test validates both "first 3 accepted" and "4th rejected".
     */
    #[Test]
    public function rateLimiterAllowsThreeRequestsThenRejects(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RateLimiterFactory $factory */
        $factory = $container->get('limiter.access_request_ip');
        $limiter = $factory->create('198.51.100.42');

        // First 3 requests must be accepted
        for ($i = 1; $i <= 3; ++$i) {
            $this->assertTrue(
                $limiter->consume()->isAccepted(),
                \sprintf('Request %d should be accepted', $i),
            );
        }

        // Fourth request from the same IP must be rate limited
        $this->assertFalse(
            $limiter->consume()->isAccepted(),
            'Fourth request should be rate limited',
        );
    }

    #[Test]
    public function aThrottledRequestIsToldWhenToRetry(): void
    {
        $client = self::createClient();
        // The limiter's array pool dies with the kernel, which the browser reboots between
        // requests unless told not to.
        $client->disableReboot();

        /** @var RateLimiterFactory $factory */
        $factory = self::getContainer()->get('limiter.access_request_ip');
        $limiter = $factory->create('127.0.0.1');
        for ($i = 0; $i < 3; ++$i) {
            $this->assertTrue($limiter->consume()->isAccepted());
        }

        $response = $client->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'throttled@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) ($response->getHeaders(false)['retry-after'][0] ?? 0));
    }
}
