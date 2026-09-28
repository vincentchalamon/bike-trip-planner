<?php

declare(strict_types=1);

namespace App\Tests\Unit\RateLimiter;

use App\RateLimiter\RetryAfter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimit;

final class RetryAfterTest extends TestCase
{
    #[Test]
    public function countsTheWholeSecondsUntilTheLimiterFreesAToken(): void
    {
        $clock = new MockClock('2026-09-28 12:00:00');

        self::assertSame(42, RetryAfter::seconds($this->limitFreeAt($clock->now()->modify('+42 seconds')), $clock));
    }

    #[Test]
    public function neverTellsTheClientToRetryInTheSameSecond(): void
    {
        $clock = new MockClock('2026-09-28 12:00:00');

        self::assertSame(1, RetryAfter::seconds($this->limitFreeAt($clock->now()), $clock));
        self::assertSame(1, RetryAfter::seconds($this->limitFreeAt($clock->now()->modify('-5 seconds')), $clock));
    }

    private function limitFreeAt(\DateTimeImmutable $retryAfter): RateLimit
    {
        return new RateLimit(0, $retryAfter, false, 10);
    }
}
