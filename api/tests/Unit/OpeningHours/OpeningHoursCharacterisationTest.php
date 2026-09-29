<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpeningHours;

use App\Accommodation\SeasonalityChecker;
use App\Engine\OpeningHours;
use App\InRide\OpeningHoursParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Golden master of the opening-hours verdicts, captured from the readers before
 * they shared a grammar. Every row pins how one `opening_hours` string is judged
 * by planning (hour x weekday), by in-ride (date-time, with its closing time) and
 * by the accommodation seasonality check (month), including the malformed shapes
 * each reader treats its own way.
 *
 * A failing row is a verdict change: fix the code, or, for a deliberate fix,
 * update the row in the commit that makes it and list old vs new there.
 */
final class OpeningHoursCharacterisationTest extends TestCase
{
    /**
     * @return array{hours: list<float>, times: list<string>, planning: array<string, list<string>|null>, inRide: array<string, array{string, array<int, string>}>, seasonality: array<string, string>}
     */
    private static function fixture(): array
    {
        /** @var array{hours: list<float>, times: list<string>, planning: array<string, list<string>|null>, inRide: array<string, array{string, array<int, string>}>, seasonality: array<string, string>} $fixture */
        $fixture = require __DIR__.'/Fixtures/characterisation.php';

        return $fixture;
    }

    /**
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function planningRows(): iterable
    {
        foreach (self::fixture()['planning'] as $spec => $expected) {
            yield json_encode((string) $spec, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) => [(string) $spec, $expected];
        }
    }

    /**
     * @return iterable<string, array{string, string, array<int, string>}>
     */
    public static function inRideRows(): iterable
    {
        foreach (self::fixture()['inRide'] as $spec => [$statuses, $closesAt]) {
            yield json_encode((string) $spec, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) => [(string) $spec, $statuses, $closesAt];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function seasonalityRows(): iterable
    {
        foreach (self::fixture()['seasonality'] as $spec => $months) {
            yield json_encode((string) $spec, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) => [(string) $spec, $months];
        }
    }

    /**
     * @param list<string>|null $expected
     */
    #[Test]
    #[DataProvider('planningRows')]
    public function planningVerdictIsUnchanged(string $spec, ?array $expected): void
    {
        $parsed = OpeningHours::parse($spec);

        if (null === $expected) {
            self::assertNull($parsed);

            return;
        }

        self::assertInstanceOf(OpeningHours::class, $parsed);

        $actual = [];
        foreach ([null, 1, 2, 3, 4, 5, 6, 7] as $weekday) {
            $line = '';
            foreach (self::fixture()['hours'] as $hour) {
                $verdict = $parsed->isOpenAt($hour, $weekday);
                $line .= null === $verdict ? 'N' : ($verdict ? 'T' : 'F');
            }

            $actual[] = $line;
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @param array<int, string> $expectedClosesAt
     */
    #[Test]
    #[DataProvider('inRideRows')]
    public function inRideVerdictIsUnchanged(string $spec, string $expectedStatuses, array $expectedClosesAt): void
    {
        $parser = new OpeningHoursParser();
        $timezone = new \DateTimeZone('Europe/Paris');

        $statuses = '';
        $closesAt = [];
        foreach (self::fixture()['times'] as $index => $time) {
            $now = new \DateTimeImmutable($time, $timezone);
            $statuses .= $parser->status($spec, $now)->name[0];

            $closing = $parser->closesAt($spec, $now);
            if ($closing instanceof \DateTimeImmutable) {
                $closesAt[$index] = $closing->format(\DATE_ATOM);
            }
        }

        self::assertSame($expectedStatuses, $statuses);
        self::assertSame($expectedClosesAt, $closesAt);
    }

    #[Test]
    #[DataProvider('seasonalityRows')]
    public function seasonalityVerdictIsUnchanged(string $spec, string $expected): void
    {
        $checker = new SeasonalityChecker();
        $timezone = new \DateTimeZone('Europe/Paris');

        $actual = '';
        foreach (range(1, 12) as $month) {
            $verdict = $checker->isLikelyOpen(new \DateTimeImmutable(\sprintf('2024-%02d-15', $month), $timezone), ['opening_hours' => $spec]);
            $actual .= null === $verdict ? 'N' : ($verdict ? 'T' : 'F');
        }

        self::assertSame($expected, $actual);
    }
}
