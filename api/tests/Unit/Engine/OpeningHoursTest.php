<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\OpeningHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser is deliberately narrow: it must read the common OSM shapes and
 * return null on everything else, so callers can tell "closed" from "unknown".
 */
final class OpeningHoursTest extends TestCase
{
    /**
     * @return iterable<string, array{string, float, int, bool}>
     */
    public static function knownSchedules(): iterable
    {
        // spec, decimal hour, ISO weekday, expected openness
        yield '24/7 is always open' => ['24/7', 3.5, 3, true];
        yield 'bare span, inside' => ['09:00-19:00', 12.0, 3, true];
        yield 'bare span, outside' => ['09:00-19:00', 20.0, 3, false];
        yield 'split day, in the morning slot' => ['07:00-13:00,15:00-19:30', 8.0, 2, true];
        yield 'split day, during the break' => ['07:00-13:00,15:00-19:30', 14.0, 2, false];
        yield 'split day, inside the half-hour end' => ['07:00-13:00,15:00-19:30', 19.25, 2, true];
        yield 'weekday range, on a covered day' => ['Mo-Fr 08:00-18:00', 10.0, 5, true];
        yield 'weekday range, on an uncovered day' => ['Mo-Fr 08:00-18:00', 10.0, 6, false];
        yield 'weekday list' => ['Mo,We,Fr 08:00-12:00', 10.0, 3, true];
        yield 'weekday list, day not listed' => ['Mo,We,Fr 08:00-12:00', 10.0, 4, false];
        yield 'range wrapping the week end' => ['Sa-Su 10:00-18:00', 12.0, 7, true];
        yield 'explicit day off' => ['Tu-Su 10:00-18:00; Mo off', 12.0, 1, false];
        yield 'explicit day off, other days stand' => ['Tu-Su 10:00-18:00; Mo off', 12.0, 2, true];
        yield 'later rule overrides earlier one' => ['Mo-Su 09:00-19:00; Su 10:00-12:00', 15.0, 7, false];
        yield 'public holiday rule is ignored' => ['Mo-Sa 09:00-19:00; PH off', 10.0, 6, true];
        yield 'lowercase weekdays' => ['mo-fr 09:00-17:00', 10.0, 1, true];
        yield 'span crossing midnight, late evening' => ['Mo-Su 18:00-02:00', 23.0, 4, true];
        yield 'span crossing midnight, afternoon' => ['Mo-Su 18:00-02:00', 15.0, 4, false];
        yield 'single-digit hour' => ['Mo-Fr 8:00-18:00', 9.0, 1, true];
    }

    #[Test]
    #[DataProvider('knownSchedules')]
    public function commonOsmShapesAreUnderstood(string $spec, float $hour, int $weekday, bool $expected): void
    {
        $hours = OpeningHours::parse($spec);

        self::assertInstanceOf(OpeningHours::class, $hours, \sprintf('"%s" must be understood', $spec));
        self::assertSame($expected, $hours->isOpenAt($hour, $weekday));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedSchedules(): iterable
    {
        yield 'empty string' => [''];
        yield 'month range' => ['Apr-Oct 10:00-20:00'];
        yield 'week selector' => ['week 1-53/2 Mo-Fr 09:00-17:00'];
        yield 'nth weekday' => ['Su[1] 10:00-12:00'];
        yield 'variable time' => ['sunrise-sunset'];
        yield 'open-ended' => ['Mo-Fr 09:00+'];
        yield 'unknown keyword' => ['unknown'];
        yield 'free-form comment' => ['Mo-Fr 09:00-17:00 "ring the bell"'];
        yield 'plain prose' => ['summer only'];
        yield 'weekday selector without hours' => ['Mo-Fr'];
        yield 'out-of-range hour' => ['Mo-Fr 09:00-25:00'];
    }

    #[Test]
    #[DataProvider('unsupportedSchedules')]
    public function unrecognisedShapesYieldNull(string $spec): void
    {
        self::assertNull(OpeningHours::parse($spec), \sprintf('"%s" must stay unknown, never be read as closed', $spec));
    }

    #[Test]
    public function weekdayDependentScheduleIsInconclusiveWithoutAWeekday(): void
    {
        $hours = OpeningHours::parse('Mo-Fr 09:00-17:00');

        self::assertInstanceOf(OpeningHours::class, $hours);
        // 10:00 is inside the slot on Mo-Fr but outside it on Sa-Su: with no date,
        // the answer depends on the weekday, so there is nothing to conclude.
        self::assertNull($hours->isOpenAt(10.0));
        // 03:00 is outside the slot every single day, so the weekday does not matter.
        self::assertFalse($hours->isOpenAt(3.0));
    }

    #[Test]
    public function weekdayIndependentScheduleAnswersWithoutAWeekday(): void
    {
        $hours = OpeningHours::parse('09:00-19:00');

        self::assertInstanceOf(OpeningHours::class, $hours);
        self::assertTrue($hours->isOpenAt(12.0));
        self::assertFalse($hours->isOpenAt(21.0));
    }
}
