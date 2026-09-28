<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\ApiResource\TripRequest;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TripLockerTest extends TestCase
{
    private TripLocker $locker;

    #[\Override]
    protected function setUp(): void
    {
        // Late evening in UTC, already tomorrow in Paris: "today" is the UTC day.
        $this->locker = new TripLocker(new MockClock('2026-07-01 23:30:00', 'UTC'));
    }

    #[Test]
    public function isLockedReturnsFalseWhenStartDateIsNull(): void
    {
        $request = new TripRequest();
        $request->startDate = null;

        $this->assertFalse($this->locker->isLocked($request));
    }

    #[Test]
    public function isLockedReturnsFalseWhenStartDateIsInFuture(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-02', new \DateTimeZone('UTC'));

        $this->assertFalse($this->locker->isLocked($request));
    }

    #[Test]
    public function isLockedReturnsTrueWhenStartDateIsToday(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('UTC'));

        $this->assertTrue($this->locker->isLocked($request));
    }

    #[Test]
    public function isLockedReturnsTrueWhenStartDateIsInPast(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-06-30', new \DateTimeZone('UTC'));

        $this->assertTrue($this->locker->isLocked($request));
    }

    #[Test]
    public function assertNotLockedThrowsWhenTripIsLocked(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-06-30', new \DateTimeZone('UTC'));

        try {
            $this->locker->assertNotLocked($request);
            $this->fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            $this->assertSame(423, $httpException->getStatusCode());
        }
    }

    #[Test]
    public function assertNotLockedDoesNotThrowWhenTripIsNotLocked(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-02', new \DateTimeZone('UTC'));

        $this->expectNotToPerformAssertions();
        $this->locker->assertNotLocked($request);
    }
}
