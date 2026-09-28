<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpeningHours;

use App\OpeningHours\PublicHolidayCalendar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PublicHolidayCalendarTest extends TestCase
{
    #[Test]
    public function aHolidayOfAnyListedCountryCounts(): void
    {
        $calendar = new PublicHolidayCalendar();
        $bastilleDay = new \DateTimeImmutable('2025-07-14 12:00', new \DateTimeZone('Europe/Paris'));
        $belgianNationalDay = new \DateTimeImmutable('2025-07-21 12:00', new \DateTimeZone('Europe/Brussels'));

        self::assertTrue($calendar->isHoliday($bastilleDay, ['FR']));
        self::assertFalse($calendar->isHoliday($bastilleDay, ['BE']));
        self::assertTrue($calendar->isHoliday($belgianNationalDay, ['FR', 'BE']));
        self::assertFalse($calendar->isHoliday($belgianNationalDay, ['FR']));
        self::assertFalse($calendar->isHoliday(new \DateTimeImmutable('2025-07-22 12:00'), ['FR', 'BE']));
    }

    #[Test]
    public function aCountryWithoutProviderIsAWorkingDay(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Failed to compute public holiday');

        self::assertFalse(new PublicHolidayCalendar($logger)->isHoliday(new \DateTimeImmutable('2025-07-14'), ['XX']));
    }
}
