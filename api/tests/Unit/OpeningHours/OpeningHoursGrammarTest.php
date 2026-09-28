<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpeningHours;

use App\OpeningHours\Modifier;
use App\OpeningHours\OpeningHoursGrammar;
use App\OpeningHours\SelectorKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The grammar structures and never judges: verdicts are pinned by
 * {@see OpeningHoursCharacterisationTest}, this only checks the model shape.
 */
final class OpeningHoursGrammarTest extends TestCase
{
    #[Test]
    public function readsSelectorSpansAndModifierOfEachRule(): void
    {
        $schedule = OpeningHoursGrammar::parse(' Mo-Fr 09:00-12:00,14:00-18:30; Su off ');

        self::assertSame('Mo-Fr 09:00-12:00,14:00-18:30; Su off', $schedule->text);
        self::assertCount(2, $schedule->rules);

        [$weekdays, $sunday] = $schedule->rules;
        self::assertNotNull($weekdays->selector);
        self::assertSame(SelectorKind::WEEKDAYS, $weekdays->selector[0]->kind);
        self::assertSame([1, 2, 3, 4, 5], $weekdays->selector[0]->weekdayList());
        self::assertTrue($weekdays->selector[0]->canonical);
        self::assertNull($weekdays->modifier);
        self::assertCount(2, $weekdays->spans);
        self::assertSame([14, 0, 18, 30], [$weekdays->spans[1]->startHour, $weekdays->spans[1]->startMinute, $weekdays->spans[1]->endHour, $weekdays->spans[1]->endMinute]);
        self::assertTrue($weekdays->spans[1]->followsSingleComma());

        self::assertSame(Modifier::OFF, $sunday->modifier);
        self::assertSame([], $sunday->spans);
    }

    #[Test]
    public function keepsTheSpellingVariantsItTolerates(): void
    {
        $rule = OpeningHoursGrammar::parse('mo - Fr 09:00 - 12:00 open')->rules[0];

        self::assertNotNull($rule->selector);
        self::assertSame(SelectorKind::WEEKDAYS, $rule->selector[0]->kind);
        self::assertFalse($rule->selector[0]->canonical);
        self::assertFalse($rule->spans[0]->isCompact());
        self::assertSame(Modifier::OPEN, $rule->modifier);
    }

    #[Test]
    public function wrapsAWeekdayRangePastSunday(): void
    {
        $selector = OpeningHoursGrammar::parse('Fr-Mo 10:00-12:00')->rules[0]->selector;

        self::assertNotNull($selector);
        self::assertSame([5, 6, 7, 1], $selector[0]->weekdayList());
    }

    #[Test]
    public function modelsHolidaysDatesAndUnknownTokensWithoutRejectingThem(): void
    {
        [$always, $holiday, $date, $nth] = OpeningHoursGrammar::parse('24/7; PH off; dec 25 10:00-12:00; Su[1] 10:00-12:00')->rules;

        self::assertTrue($always->always);

        self::assertTrue($holiday->holidayScoped);
        self::assertSame(SelectorKind::PUBLIC_HOLIDAY, $holiday->selector[0]->kind ?? null);

        self::assertNotNull($date->selector);
        self::assertSame(SelectorKind::MONTH_DAY, $date->selector[0]->kind);
        self::assertSame([12, 25], [$date->selector[0]->from, $date->selector[0]->to]);

        self::assertSame(SelectorKind::UNKNOWN, $nth->selector[0]->kind ?? null);
        self::assertCount(1, $nth->spans);
    }

    #[Test]
    public function aTimePartThatIsNotSpansStaysInTheSelector(): void
    {
        $rule = OpeningHoursGrammar::parse('Mo-Fr 09:00+')->rules[0];

        self::assertSame([], $rule->spans);
        self::assertNotNull($rule->selector);
        self::assertSame(SelectorKind::UNKNOWN, $rule->selector[0]->kind);
    }

    #[Test]
    public function emptyRulesAreDropped(): void
    {
        self::assertSame([], OpeningHoursGrammar::parse(' ; ;; ')->rules);
        self::assertSame([], OpeningHoursGrammar::parse('')->rules);
    }
}
