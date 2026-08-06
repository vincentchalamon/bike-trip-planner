<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\InRide\OpeningHoursParser;
use App\InRide\OpeningStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpeningHoursParserTest extends TestCase
{
    private OpeningHoursParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OpeningHoursParser();
    }

    /**
     * @return iterable<string, array{string, string, OpeningStatus}>
     */
    public static function statusProvider(): iterable
    {
        // 2024-06-03 is a Monday, 2024-06-08 is a Saturday, 2024-06-09 is a Sunday.
        yield '24/7 always open at noon' => ['24/7', '2024-06-03 12:00:00', OpeningStatus::OPEN];
        yield '24/7 always open at midnight' => ['24/7', '2024-06-03 00:00:00', OpeningStatus::OPEN];

        yield 'Mo-Sa range, monday inside hours' => ['Mo-Sa 09:00-19:00', '2024-06-03 10:00:00', OpeningStatus::OPEN];
        yield 'Mo-Sa range, monday before opening' => ['Mo-Sa 09:00-19:00', '2024-06-03 08:59:00', OpeningStatus::CLOSED];
        yield 'Mo-Sa range, monday at closing' => ['Mo-Sa 09:00-19:00', '2024-06-03 19:00:00', OpeningStatus::CLOSED];
        yield 'Mo-Sa range, sunday closed (omitted day)' => ['Mo-Sa 09:00-19:00', '2024-06-09 10:00:00', OpeningStatus::CLOSED];

        $multi = 'Mo-Fr 09:00-12:00,14:00-18:00; Sa 09:00-12:00; Su off';
        yield 'multi-rule open morning' => [$multi, '2024-06-03 10:00:00', OpeningStatus::OPEN];
        yield 'multi-rule closed lunch' => [$multi, '2024-06-03 13:00:00', OpeningStatus::CLOSED];
        yield 'multi-rule open afternoon' => [$multi, '2024-06-03 15:00:00', OpeningStatus::OPEN];
        yield 'multi-rule saturday morning open' => [$multi, '2024-06-08 10:00:00', OpeningStatus::OPEN];
        yield 'multi-rule saturday afternoon closed' => [$multi, '2024-06-08 15:00:00', OpeningStatus::CLOSED];
        yield 'multi-rule sunday off' => [$multi, '2024-06-09 10:00:00', OpeningStatus::CLOSED];

        yield 'PH off overrides Mo-Fr on bastille day' => [
            'Mo-Fr 09:00-18:00; PH off',
            '2024-07-14 12:00:00', // Bastille day (Sunday in 2024 — but PH off matters when PH on weekday)
            OpeningStatus::CLOSED,
        ];
        yield 'PH off on May 1 (a wednesday in 2024)' => [
            'Mo-Fr 09:00-18:00; PH off',
            '2024-05-01 12:00:00',
            OpeningStatus::CLOSED,
        ];
        yield 'PH explicit hours overrides Mo-Fr' => [
            'Mo-Su 11:30-23:00; PH 11:30-23:00',
            '2024-05-01 12:00:00',
            OpeningStatus::OPEN,
        ];

        yield 'dec 25 off' => ['Mo-Su 09:00-18:00; dec 25 off', '2024-12-25 10:00:00', OpeningStatus::CLOSED];
        yield 'dec 25 normal day' => ['Mo-Su 09:00-18:00; dec 25 off', '2024-12-24 10:00:00', OpeningStatus::OPEN];

        // No parseable rule at all — the tag says nothing, so the POI stays
        // visible with a warning rather than being hidden as closed.
        yield 'empty tag is unknown' => ['', '2024-06-03 10:00:00', OpeningStatus::UNKNOWN];
        yield 'garbage tag is unknown' => ['garbage data here', '2024-06-03 10:00:00', OpeningStatus::UNKNOWN];

        yield 'overnight range, before midnight' => ['Mo-Su 22:00-02:00', '2024-06-03 23:00:00', OpeningStatus::OPEN];
        yield 'overnight range, after midnight' => ['Mo-Su 22:00-02:00', '2024-06-04 01:00:00', OpeningStatus::OPEN];
        yield 'overnight range, gap time' => ['Mo-Su 22:00-02:00', '2024-06-04 03:00:00', OpeningStatus::CLOSED];

        yield 'single weekday Mo open' => ['Mo 10:00-12:00', '2024-06-03 11:00:00', OpeningStatus::OPEN];
        yield 'single weekday Mo closed on Tue' => ['Mo 10:00-12:00', '2024-06-04 11:00:00', OpeningStatus::CLOSED];

        yield 'day list Mo,We,Fr on Wednesday' => ['Mo,We,Fr 10:00-12:00', '2024-06-05 11:00:00', OpeningStatus::OPEN];
        yield 'day list Mo,We,Fr on Tuesday' => ['Mo,We,Fr 10:00-12:00', '2024-06-04 11:00:00', OpeningStatus::CLOSED];

        // Wraparound day range Fr-Mo covers Fri, Sat, Sun, Mon.
        yield 'wraparound Fr-Mo on Saturday' => ['Fr-Mo 10:00-12:00', '2024-06-08 11:00:00', OpeningStatus::OPEN];
        yield 'wraparound Fr-Mo on Monday' => ['Fr-Mo 10:00-12:00', '2024-06-03 11:00:00', OpeningStatus::OPEN];
        yield 'wraparound Fr-Mo on Wednesday' => ['Fr-Mo 10:00-12:00', '2024-06-05 11:00:00', OpeningStatus::CLOSED];

        // 24:00 is only valid as an end marker — `24:30` start is malformed, so
        // the whole tag is unparseable: unknown, not a false "closed".
        yield 'malformed start hour 24 is unknown' => ['24:30-25:00', '2024-06-05 11:00:00', OpeningStatus::UNKNOWN];

        // 24:30 end is malformed: PHP would silently normalise it to `00:30 next
        // day`, so the parser rejects the whole (single) rule -> unknown.
        yield 'malformed end hour 24 with non-zero minutes is unknown' => ['22:00-24:30', '2024-06-05 22:30:00', OpeningStatus::UNKNOWN];

        // Public holidays: cover both FR and BE locales so the parser stays
        // useful for Belgian itineraries.
        yield 'FR Bastille Day (Jul 14) marks PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-07-14 12:00:00', OpeningStatus::CLOSED];
        yield 'BE National Day (Jul 21) marks PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-07-21 12:00:00', OpeningStatus::CLOSED];
        // Day that is a holiday neither in FR nor BE — must read as open.
        yield 'non-holiday Wednesday with PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-06-05 12:00:00', OpeningStatus::OPEN];

        // Regression for the overnight bleed bug: `22:00-02:00; PH off` opens
        // late on a normal night, so on Bastille Day morning (00:30) yesterday's
        // overnight slice would normally bleed through. But today (Jul 14) is
        // explicitly closed by the `PH off` rule, so the venue must read as
        // closed — `intervalsForDate` skips yesterday's overnight slice when
        // today returns `[]` (explicitly closed).
        yield 'PH off blocks overnight bleed at 00:30 Bastille Day' => ['22:00-02:00; PH off', '2024-07-14 00:30:00', OpeningStatus::CLOSED];
        // Sanity check: the same tag on a non-holiday morning at 00:30 stays
        // open because yesterday's overnight slice bleeds through normally.
        yield 'overnight bleed allowed at 00:30 on a regular day' => ['22:00-02:00', '2024-06-05 00:30:00', OpeningStatus::OPEN];

        // Guards `intervalsForSingleDate()`: a single-day rule whose day does
        // not match today (Tue) must return null ("no rule matched"), NOT `[]`,
        // otherwise Monday's overnight slice would be discarded and 01:00 Tue
        // would read CLOSED instead of OPEN. See nightOverflowReliesOnNullNotEmpty.
        yield 'single-day overnight bleeds into the next unmatched day' => ['Mo 22:00-02:00', '2024-06-04 01:00:00', OpeningStatus::OPEN];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function statusReturnsTheTriStateVerdict(string $tag, string $nowStr, OpeningStatus $expected): void
    {
        $now = new \DateTimeImmutable($nowStr);
        self::assertSame($expected, $this->parser->status($tag, $now));
    }

    /**
     * Explicit proof that `intervalsForSingleDate()` returning null (not `[]`)
     * for an unmatched single-day rule is load-bearing: Monday's `22:00-02:00`
     * must bleed into Tuesday 01:00. Mutating that return to `[]` flips this to
     * CLOSED.
     */
    #[Test]
    public function nightOverflowReliesOnNullNotEmpty(): void
    {
        $now = new \DateTimeImmutable('2024-06-04 01:00:00'); // Tuesday
        self::assertSame(OpeningStatus::OPEN, $this->parser->status('Mo 22:00-02:00', $now));
    }

    #[Test]
    public function closesAtReturnsEndOfCurrentInterval(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 10:00:00');
        $closes = $this->parser->closesAt('Mo-Fr 09:00-12:00,14:00-18:00', $now);

        self::assertNotNull($closes);
        self::assertSame('2024-06-03 12:00:00', $closes->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function closesAtReturnsNullWhenClosed(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 13:00:00');
        $closes = $this->parser->closesAt('Mo-Fr 09:00-12:00,14:00-18:00', $now);

        self::assertNull($closes);
    }

    #[Test]
    public function closesAtReturnsNullForInvalidTag(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 13:00:00');
        self::assertNull($this->parser->closesAt('', $now));
        self::assertNull($this->parser->closesAt('garbage', $now));
    }

    #[Test]
    public function closesAtFor247(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 13:00:00');
        $closes = $this->parser->closesAt('24/7', $now);

        self::assertNotNull($closes);
        self::assertSame('2024-06-04 00:00:00', $closes->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function closesAtOvernightReturnsNextDay(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 23:30:00');
        $closes = $this->parser->closesAt('Mo-Su 22:00-02:00', $now);

        self::assertNotNull($closes);
        self::assertSame('2024-06-04 02:00:00', $closes->format('Y-m-d H:i:s'));
    }
}
