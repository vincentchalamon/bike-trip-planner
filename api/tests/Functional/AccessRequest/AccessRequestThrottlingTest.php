<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Tests IP-based rate limiting on POST /access-requests.
 *
 * The rate limiter is configured to allow 3 requests per hour per IP.
 * In the test environment, the cache pool is array-based (in-memory, reset between kernels).
 * The kernel is rebooted before each test to ensure a clean rate limiter state.
 */
#[ResetDatabase]
final class AccessRequestThrottlingTest extends ApiTestCase
{
    use Factories;

    /**
     * Verifies that after exactly 3 requests, the 4th is rate-limited to 429.
     * This single test validates both "first 3 accepted" and "4th rejected".
     */
    #[Test]
    public function rateLimiterAllowsThreeRequestsThenRejects(): void
    {
        $client = self::createClient();

        // First 3 requests must be accepted
        for ($i = 1; $i <= 3; ++$i) {
            $client->request('POST', '/access-requests', [
                'headers' => ['Content-Type' => 'application/ld+json'],
                'json' => ['email' => \sprintf('user%d@throttle-test.com', $i)],
            ]);
            $this->assertResponseStatusCodeSame(202, \sprintf('Request %d should be accepted (202)', $i));
        }

        // Fourth request from the same IP must be rate limited
        $client->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'user4@throttle-test.com'],
        ]);
        $this->assertResponseStatusCodeSame(429, 'Fourth request should be rate limited (429)');
    }
}
