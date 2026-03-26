<?php

declare(strict_types=1);

namespace App\Tests\Unit\Accommodation;

use Override;
use DateTimeImmutable;
use App\Accommodation\SeasonalityChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeasonalityCheckerTest extends TestCase
{
    private SeasonalityChecker $checker;

    #[Override]
    protected function setUp(): void
    {
        $this->checker = new SeasonalityChecker();
    }

    // -------------------------------------------------------------------------
    // seasonal=yes (no opening_hours)
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function seasonalYesProvider(): iterable
    {
        // Open months: April–October
        yield 'April is open' => ['2024-04-15', true];
        yield 'May is open' => ['2024-05-01', true];
        yield 'July is open' => ['2024-07-20', true];
        yield 'October is open' => ['2024-10-31', true];

        // Closed months: November–March
        yield 'November is closed' => ['2024-11-01', false];
        yield 'December is closed' => ['2024-12-25', false];
        yield 'January is closed' => ['2024-01-10', false];
        yield 'February is closed' => ['2024-02-14', false];
        yield 'March is closed' => ['2024-03-31', false];
    }

    #[DataProvider('seasonalYesProvider')]
    #[Test]
    public function seasonalYesWithoutOpeningHours(string $date, bool $expectedOpen): void
    {
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable($date),
            ['seasonal' => 'yes'],
        );

        $this->assertSame($expectedOpen, $result);
    }

    // -------------------------------------------------------------------------
    // opening_hours month-range patterns
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function openingHoursProvider(): iterable
    {
        // Apr-Oct contiguous range
        yield 'Apr-Oct: April is open' => ['Apr-Oct', '2024-04-01', true];
        yield 'Apr-Oct: July is open' => ['Apr-Oct', '2024-07-15', true];
        yield 'Apr-Oct: October is open' => ['Apr-Oct', '2024-10-31', true];
        yield 'Apr-Oct: March is closed' => ['Apr-Oct', '2024-03-15', false];
        yield 'Apr-Oct: November is closed' => ['Apr-Oct', '2024-11-01', false];

        // May-Sep contiguous range
        yield 'May-Sep: June is open' => ['May-Sep', '2024-06-10', true];
        yield 'May-Sep: April is closed' => ['May-Sep', '2024-04-30', false];
        yield 'May-Sep: October is closed' => ['May-Sep', '2024-10-01', false];

        // With time suffix — should be stripped
        yield 'Apr-Oct with time: August is open' => ['Apr-Oct 09:00-20:00', '2024-08-01', true];
        yield 'Apr-Oct with time: January is closed' => ['Apr-Oct 09:00-20:00', '2024-01-01', false];

        // Wrap-around range (Oct-Mar: open from October to March)
        yield 'Oct-Mar: November is open' => ['Oct-Mar', '2024-11-15', true];
        yield 'Oct-Mar: January is open' => ['Oct-Mar', '2024-01-10', true];
        yield 'Oct-Mar: July is closed' => ['Oct-Mar', '2024-07-01', false];
    }

    #[DataProvider('openingHoursProvider')]
    #[Test]
    public function openingHoursMonthRange(string $openingHours, string $date, bool $expectedOpen): void
    {
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable($date),
            ['opening_hours' => $openingHours],
        );

        $this->assertSame($expectedOpen, $result);
    }

    // -------------------------------------------------------------------------
    // Unparseable or absent tags → null
    // -------------------------------------------------------------------------

    #[Test]
    public function noTagsReturnsNull(): void
    {
        $result = $this->checker->isLikelyOpen(new DateTimeImmutable('2024-07-01'), []);

        $this->assertNull($result);
    }

    #[Test]
    public function unrelatedTagsReturnNull(): void
    {
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable('2024-07-01'),
            ['tourism' => 'camp_site', 'name' => 'Happy Campers'],
        );

        $this->assertNull($result);
    }

    #[Test]
    public function unparseableOpeningHoursReturnNull(): void
    {
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable('2024-07-01'),
            ['opening_hours' => 'Mo-Fr 08:00-18:00'],
        );

        $this->assertNull($result);
    }

    #[Test]
    public function openingHoursTakesPrecedenceOverSeasonal(): void
    {
        // Apr-Oct opening_hours: July is open even with seasonal=yes
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable('2024-07-01'),
            ['opening_hours' => 'Apr-Oct', 'seasonal' => 'yes'],
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function openingHoursTakesPrecedenceOverSeasonalInWinter(): void
    {
        // Oct-Mar opening_hours: January is open (wrap-around), even if seasonal=yes would say closed
        $result = $this->checker->isLikelyOpen(
            new DateTimeImmutable('2024-01-15'),
            ['opening_hours' => 'Oct-Mar', 'seasonal' => 'yes'],
        );

        $this->assertTrue($result);
    }
}
