<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

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
    use Factories;

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
}
