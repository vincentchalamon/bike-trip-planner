<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\InRide\OpeningHoursParser;
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
     * @return iterable<string, array{string, string, bool}>
     */
    public static function isOpenNowProvider(): iterable
    {
        // 2024-06-03 is a Monday, 2024-06-08 is a Saturday, 2024-06-09 is a Sunday.
        yield '24/7 always open at noon' => ['24/7', '2024-06-03 12:00:00', true];
        yield '24/7 always open at midnight' => ['24/7', '2024-06-03 00:00:00', true];

        yield 'Mo-Sa range, monday inside hours' => ['Mo-Sa 09:00-19:00', '2024-06-03 10:00:00', true];
        yield 'Mo-Sa range, monday before opening' => ['Mo-Sa 09:00-19:00', '2024-06-03 08:59:00', false];
        yield 'Mo-Sa range, monday at closing' => ['Mo-Sa 09:00-19:00', '2024-06-03 19:00:00', false];
        yield 'Mo-Sa range, sunday closed' => ['Mo-Sa 09:00-19:00', '2024-06-09 10:00:00', false];

        $multi = 'Mo-Fr 09:00-12:00,14:00-18:00; Sa 09:00-12:00; Su off';
        yield 'multi-rule open morning' => [$multi, '2024-06-03 10:00:00', true];
        yield 'multi-rule closed lunch' => [$multi, '2024-06-03 13:00:00', false];
        yield 'multi-rule open afternoon' => [$multi, '2024-06-03 15:00:00', true];
        yield 'multi-rule saturday morning open' => [$multi, '2024-06-08 10:00:00', true];
        yield 'multi-rule saturday afternoon closed' => [$multi, '2024-06-08 15:00:00', false];
        yield 'multi-rule sunday off' => [$multi, '2024-06-09 10:00:00', false];

        yield 'PH off overrides Mo-Fr on bastille day' => [
            'Mo-Fr 09:00-18:00; PH off',
            '2024-07-14 12:00:00', // Bastille day (Sunday in 2024 — but PH off matters when PH on weekday)
            false,
        ];
        yield 'PH off on May 1 (a wednesday in 2024)' => [
            'Mo-Fr 09:00-18:00; PH off',
            '2024-05-01 12:00:00',
            false,
        ];
        yield 'PH explicit hours overrides Mo-Fr' => [
            'Mo-Su 11:30-23:00; PH 11:30-23:00',
            '2024-05-01 12:00:00',
            true,
        ];

        yield 'dec 25 off' => ['Mo-Su 09:00-18:00; dec 25 off', '2024-12-25 10:00:00', false];
        yield 'dec 25 normal day' => ['Mo-Su 09:00-18:00; dec 25 off', '2024-12-24 10:00:00', true];

        yield 'empty tag' => ['', '2024-06-03 10:00:00', false];
        yield 'invalid tag' => ['garbage data here', '2024-06-03 10:00:00', false];

        yield 'overnight range, before midnight' => ['Mo-Su 22:00-02:00', '2024-06-03 23:00:00', true];
        yield 'overnight range, after midnight' => ['Mo-Su 22:00-02:00', '2024-06-04 01:00:00', true];
        yield 'overnight range, gap time' => ['Mo-Su 22:00-02:00', '2024-06-04 03:00:00', false];

        yield 'single weekday Mo open' => ['Mo 10:00-12:00', '2024-06-03 11:00:00', true];
        yield 'single weekday Mo closed on Tue' => ['Mo 10:00-12:00', '2024-06-04 11:00:00', false];

        yield 'day list Mo,We,Fr on Wednesday' => ['Mo,We,Fr 10:00-12:00', '2024-06-05 11:00:00', true];
        yield 'day list Mo,We,Fr on Tuesday' => ['Mo,We,Fr 10:00-12:00', '2024-06-04 11:00:00', false];

        // Wraparound day range Fr-Mo covers Fri, Sat, Sun, Mon.
        yield 'wraparound Fr-Mo on Saturday' => ['Fr-Mo 10:00-12:00', '2024-06-08 11:00:00', true];
        yield 'wraparound Fr-Mo on Monday' => ['Fr-Mo 10:00-12:00', '2024-06-03 11:00:00', true];
        yield 'wraparound Fr-Mo on Wednesday' => ['Fr-Mo 10:00-12:00', '2024-06-05 11:00:00', false];

        // 24:00 is only valid as an end marker — `24:30` start is malformed.
        yield 'invalid start hour 24' => ['24:30-25:00', '2024-06-05 11:00:00', false];

        // 24:30 end is malformed: PHP would silently normalise it to `00:30 next day`.
        yield 'invalid end hour 24 with non-zero minutes' => ['22:00-24:30', '2024-06-05 22:30:00', false];

        // Public holidays: cover both FR and BE locales so the parser stays
        // useful for Belgian itineraries.
        yield 'FR Bastille Day (Jul 14) marks PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-07-14 12:00:00', false];
        yield 'BE National Day (Jul 21) marks PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-07-21 12:00:00', false];
        // Day that is a holiday neither in FR nor BE — must read as open.
        yield 'non-holiday Wednesday with PH off' => ['Mo-Su 09:00-18:00; PH off', '2024-06-05 12:00:00', true];
    }

    #[Test]
    #[DataProvider('isOpenNowProvider')]
    public function isOpenNow(string $tag, string $nowStr, bool $expected): void
    {
        $now = new \DateTimeImmutable($nowStr);
        self::assertSame($expected, $this->parser->isOpenNow($tag, $now));
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

    #[Test]
    public function isOpenForAtLeastTrueWhenCloseTimeIsAfterDeadline(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 10:00:00');
        $duration = new \DateInterval('PT1H');

        self::assertTrue($this->parser->isOpenForAtLeast('Mo-Fr 09:00-12:00', $now, $duration));
    }

    #[Test]
    public function isOpenForAtLeastFalseWhenClosesBeforeDeadline(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 11:30:00');
        $duration = new \DateInterval('PT1H');

        self::assertFalse($this->parser->isOpenForAtLeast('Mo-Fr 09:00-12:00', $now, $duration));
    }

    #[Test]
    public function isOpenForAtLeastFalseWhenClosed(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 13:00:00');
        $duration = new \DateInterval('PT1H');

        self::assertFalse($this->parser->isOpenForAtLeast('Mo-Fr 09:00-12:00', $now, $duration));
    }

    #[Test]
    public function isOpenForAtLeastFalseForInvalidTag(): void
    {
        $now = new \DateTimeImmutable('2024-06-03 10:00:00');
        $duration = new \DateInterval('PT1H');

        self::assertFalse($this->parser->isOpenForAtLeast('', $now, $duration));
        self::assertFalse($this->parser->isOpenForAtLeast('garbage', $now, $duration));
    }
}
