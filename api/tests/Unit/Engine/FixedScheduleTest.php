<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\FixedSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FixedScheduleTest extends TestCase
{
    // --- supermarket() ---

    #[Test]
    public function supermarketIsOpenDuringBusinessHours(): void
    {
        $schedule = FixedSchedule::supermarket();

        $this->assertTrue($schedule->isOpenAt(10.0));  // 10:00
        $this->assertTrue($schedule->isOpenAt(14.0));  // 14:00
        $this->assertTrue($schedule->isOpenAt(19.0));  // 19:00
    }

    #[Test]
    public function supermarketIsClosedBeforeOpening(): void
    {
        $schedule = FixedSchedule::supermarket();

        $this->assertFalse($schedule->isOpenAt(7.0));  // 07:00
        $this->assertFalse($schedule->isOpenAt(8.99)); // just before 9h
    }

    #[Test]
    public function supermarketIsClosedAfterClosing(): void
    {
        $schedule = FixedSchedule::supermarket();

        $this->assertFalse($schedule->isOpenAt(21.0)); // 21:00
    }

    #[Test]
    public function supermarketIsOpenAtBoundaries(): void
    {
        $schedule = FixedSchedule::supermarket();

        $this->assertTrue($schedule->isOpenAt(9.0));   // exactly at open
        $this->assertTrue($schedule->isOpenAt(20.0));  // exactly at close
    }

    // --- restaurant() ---

    #[Test]
    public function restaurantIsOpenDuringLunchHours(): void
    {
        $schedule = FixedSchedule::restaurant();

        $this->assertTrue($schedule->isOpenAt(12.0));  // 12:00
        $this->assertTrue($schedule->isOpenAt(13.0));  // 13:00
        $this->assertTrue($schedule->isOpenAt(14.0));  // 14:00
    }

    #[Test]
    public function restaurantIsOpenDuringDinnerHours(): void
    {
        $schedule = FixedSchedule::restaurant();

        $this->assertTrue($schedule->isOpenAt(19.0));  // 19:00
        $this->assertTrue($schedule->isOpenAt(20.0));  // 20:00
        $this->assertTrue($schedule->isOpenAt(22.0));  // 22:00
    }

    #[Test]
    public function restaurantIsClosedBetweenMeals(): void
    {
        $schedule = FixedSchedule::restaurant();

        $this->assertFalse($schedule->isOpenAt(10.0)); // morning
        $this->assertFalse($schedule->isOpenAt(15.0)); // afternoon
        $this->assertFalse($schedule->isOpenAt(23.0)); // late evening
    }

    // --- bakery() ---

    #[Test]
    public function bakeryIsOpenInTheMorning(): void
    {
        $schedule = FixedSchedule::bakery();

        $this->assertTrue($schedule->isOpenAt(7.0));   // 07:00
        $this->assertTrue($schedule->isOpenAt(9.0));   // 09:00
        $this->assertTrue($schedule->isOpenAt(13.0));  // 13:00
    }

    #[Test]
    public function bakeryIsOpenInTheAfternoon(): void
    {
        $schedule = FixedSchedule::bakery();

        $this->assertTrue($schedule->isOpenAt(15.0));  // 15:00
        $this->assertTrue($schedule->isOpenAt(17.0));  // 17:00
        $this->assertTrue($schedule->isOpenAt(19.0));  // 19:00
    }

    #[Test]
    public function bakeryIsClosedAtLunchBreak(): void
    {
        $schedule = FixedSchedule::bakery();

        $this->assertFalse($schedule->isOpenAt(14.0)); // lunch break
    }

    #[Test]
    public function bakeryIsClosedEarlyMorningAndLateEvening(): void
    {
        $schedule = FixedSchedule::bakery();

        $this->assertFalse($schedule->isOpenAt(5.0));  // too early
        $this->assertFalse($schedule->isOpenAt(21.0)); // too late
    }

    // --- noFilter() ---

    #[Test]
    public function noFilterIsAlwaysOpen(): void
    {
        $schedule = FixedSchedule::noFilter();

        $this->assertTrue($schedule->isOpenAt(0.0));
        $this->assertTrue($schedule->isOpenAt(3.0));
        $this->assertTrue($schedule->isOpenAt(12.0));
        $this->assertTrue($schedule->isOpenAt(23.0));
    }

    /**
     * @return list<array{float, bool}>
     */
    public static function provideSupermarketHours(): array
    {
        return [
            'closed at 6h' => [6.0, false],
            'open at 9h' => [9.0, true],
            'open at 12h' => [12.0, true],
            'open at 20h' => [20.0, true],
            'closed at 21h' => [21.0, false],
        ];
    }

    #[Test]
    #[DataProvider('provideSupermarketHours')]
    public function supermarketScheduleIsConsistentAtVariousHours(float $hour, bool $expectedOpen): void
    {
        $schedule = FixedSchedule::supermarket();

        $this->assertSame($expectedOpen, $schedule->isOpenAt($hour));
    }
}
