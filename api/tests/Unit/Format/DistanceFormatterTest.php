<?php

declare(strict_types=1);

namespace App\Tests\Unit\Format;

use App\Format\DecimalFormatter;
use App\Format\DistanceFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DistanceFormatterTest extends TestCase
{
    private DistanceFormatter $formatter;

    #[\Override]
    protected function setUp(): void
    {
        $this->formatter = new DistanceFormatter(new DecimalFormatter());
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'sub-kilometre stays in metres (fr)' => [480.0, 'fr', '480 m'];
        yield 'sub-kilometre stays in metres (en)' => [480.0, 'en', '480 m'];
        yield 'metres are rounded to the unit' => [480.6, 'en', '481 m'];
        yield 'just below the threshold' => [999.0, 'en', '999 m'];
        yield 'exactly one kilometre switches unit' => [1000.0, 'en', '1 km'];
        yield 'large sum in french' => [43871.0, 'fr', '43,9 km'];
        yield 'large sum in english' => [43871.0, 'en', '43.9 km'];
        yield 'no group separator on large kilometre values' => [1234567.0, 'fr', '1234,6 km'];
        yield 'trailing zero is dropped' => [10000.0, 'fr', '10 km'];
        yield 'zero' => [0.0, 'fr', '0 m'];
        yield 'negative below threshold' => [-250.0, 'fr', '-250 m'];
        yield 'negative above threshold' => [-1500.0, 'fr', '-1,5 km'];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function format(float $meters, string $locale, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->format($meters, $locale));
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function formatKilometersProvider(): iterable
    {
        yield 'sub-kilometre gap in french' => [600.0, 'fr', '0,6 km'];
        yield 'sub-kilometre gap in english' => [600.0, 'en', '0.6 km'];
        yield 'rounds half up' => [1250.0, 'en', '1.3 km'];
        yield 'zero' => [0.0, 'fr', '0 km'];
        yield 'negative' => [-600.0, 'fr', '-0,6 km'];
    }

    #[DataProvider('formatKilometersProvider')]
    #[Test]
    public function formatKilometers(float $meters, string $locale, string $expected): void
    {
        $this->assertSame($expected, $this->formatter->formatKilometers($meters, $locale));
    }
}
