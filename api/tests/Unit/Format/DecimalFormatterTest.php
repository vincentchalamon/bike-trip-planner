<?php

declare(strict_types=1);

namespace App\Tests\Unit\Format;

use App\Format\DecimalFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DecimalFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string, int, int, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'french decimal separator' => [8.5, 'fr', 1, 1, '8,5'];
        yield 'english decimal separator' => [8.5, 'en', 1, 1, '8.5'];
        yield 'constant precision keeps the trailing zero' => [9.0, 'fr', 1, 1, '9,0'];
        yield 'optional precision drops the trailing zero' => [9.0, 'fr', 0, 1, '9'];
        yield 'rounds half up' => [8.45, 'en', 1, 1, '8.5'];
        yield 'no group separator' => [12345.0, 'fr', 0, 0, '12345'];
        yield 'zero' => [0.0, 'fr', 1, 1, '0,0'];
        yield 'negative' => [-8.5, 'fr', 1, 1, '-8,5'];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function format(float $value, string $locale, int $min, int $max, string $expected): void
    {
        $this->assertSame($expected, new DecimalFormatter()->format($value, $locale, $min, $max));
    }
}
