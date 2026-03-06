<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer\Mapper;

use App\Serializer\Mapper\WaypointMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WaypointMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('gpxSymbolProvider')]
    public function gpxSymbolMapsKnownCategory(string $category, string $expected): void
    {
        self::assertSame($expected, WaypointMapper::gpxSymbol($category));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gpxSymbolProvider(): iterable
    {
        yield 'restaurant' => ['restaurant', 'Restaurant'];
        yield 'cafe' => ['cafe', 'Restaurant'];
        yield 'supermarket' => ['supermarket', 'Shopping Center'];
        yield 'bakery' => ['bakery', 'Shopping Center'];
        yield 'pharmacy' => ['pharmacy', 'Medical Facility'];
        yield 'viewpoint' => ['viewpoint', 'Scenic Area'];
        yield 'camp_site' => ['camp_site', 'Campground'];
        yield 'hostel' => ['hostel', 'Lodge'];
        yield 'hotel' => ['hotel', 'Hotel'];
        yield 'drinking_water' => ['drinking_water', 'Drinking Water'];
    }

    #[Test]
    public function gpxSymbolReturnsFallbackForUnknownCategory(): void
    {
        self::assertSame('Flag, Blue', WaypointMapper::gpxSymbol('unknown_category'));
    }

    #[Test]
    #[DataProvider('fitCoursePointTypeProvider')]
    public function fitCoursePointTypeMapsKnownCategory(string $category, int $expected): void
    {
        self::assertSame($expected, WaypointMapper::fitCoursePointType($category));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function fitCoursePointTypeProvider(): iterable
    {
        yield 'restaurant' => ['restaurant', WaypointMapper::FOOD];
        yield 'supermarket' => ['supermarket', WaypointMapper::FOOD];
        yield 'pharmacy' => ['pharmacy', WaypointMapper::FIRST_AID];
        yield 'viewpoint' => ['viewpoint', WaypointMapper::SUMMIT];
        yield 'drinking_water' => ['drinking_water', WaypointMapper::WATER];
        yield 'camp_site' => ['camp_site', WaypointMapper::GENERIC];
        yield 'hotel' => ['hotel', WaypointMapper::GENERIC];
    }

    #[Test]
    public function fitCoursePointTypeReturnsGenericForUnknownCategory(): void
    {
        self::assertSame(WaypointMapper::GENERIC, WaypointMapper::fitCoursePointType('unknown'));
    }
}
