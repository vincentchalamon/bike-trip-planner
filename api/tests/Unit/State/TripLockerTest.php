<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\ApiResource\TripRequest;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TripLockerTest extends TestCase
{
    private TripLocker $locker;

    #[\Override]
    protected function setUp(): void
    {
        $this->locker = new TripLocker();
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
        $request->startDate = new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'));

        $this->assertFalse($this->locker->isLocked($request));
    }

    #[Test]
    public function isLockedReturnsTrueWhenStartDateIsToday(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $this->assertTrue($this->locker->isLocked($request));
    }

    #[Test]
    public function isLockedReturnsTrueWhenStartDateIsInPast(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC'));

        $this->assertTrue($this->locker->isLocked($request));
    }

    #[Test]
    public function assertNotLockedThrowsWhenTripIsLocked(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC'));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(423);

        $this->locker->assertNotLocked($request);
    }

    #[Test]
    public function assertNotLockedDoesNotThrowWhenTripIsNotLocked(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'));

        $this->expectNotToPerformAssertions();
        $this->locker->assertNotLocked($request);
    }
}
