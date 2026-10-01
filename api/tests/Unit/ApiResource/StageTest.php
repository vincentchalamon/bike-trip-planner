<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StageTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function dayNumbers(): iterable
    {
        yield 'first day' => [1, '2026-06-30'];
        yield 'third day, across a month' => [3, '2026-07-02'];
        yield 'a day number below one stays on the first day' => [0, '2026-06-30'];
    }

    #[DataProvider('dayNumbers')]
    #[Test]
    public function isRiddenOnTheDayItsNumberCountsFromTheTripStart(int $dayNumber, string $expected): void
    {
        $stage = new Stage('trip-1', $dayNumber, 80000.0, 500.0, new Coordinate(45.0, 5.0), new Coordinate(45.1, 5.1));
        $start = new \DateTimeImmutable('2026-06-30', new \DateTimeZone('UTC'));

        self::assertSame($expected, $stage->dateFrom($start)->format('Y-m-d'));
        self::assertSame('2026-06-30', $start->format('Y-m-d'), 'The trip start is not modified.');
    }
}
