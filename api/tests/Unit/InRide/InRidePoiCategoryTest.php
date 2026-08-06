<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\InRide\InRidePoiCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InRidePoiCategoryTest extends TestCase
{
    #[Test]
    #[DataProvider('defaultRadiusProvider')]
    public function defaultRadiusMetersFollowsTheTier(InRidePoiCategory $category, int $expected): void
    {
        self::assertSame($expected, $category->defaultRadiusMeters());
    }

    /**
     * @return iterable<string, array{InRidePoiCategory, int}>
     */
    public static function defaultRadiusProvider(): iterable
    {
        yield 'water' => [InRidePoiCategory::WATER, 3_000];
        yield 'shelter' => [InRidePoiCategory::SHELTER, 3_000];
        yield 'food' => [InRidePoiCategory::FOOD, 3_000];
        yield 'resupply' => [InRidePoiCategory::RESUPPLY, 5_000];
        yield 'health' => [InRidePoiCategory::HEALTH, 5_000];
        yield 'mechanic' => [InRidePoiCategory::MECHANIC, 10_000];
        yield 'train' => [InRidePoiCategory::TRAIN, 10_000];
        yield 'charging' => [InRidePoiCategory::CHARGING, 10_000];
    }

    #[Test]
    public function clampRadiusUsesTheCategoryDefaultWhenNull(): void
    {
        self::assertSame(3_000, InRidePoiCategory::WATER->clampRadius(null));
        self::assertSame(10_000, InRidePoiCategory::TRAIN->clampRadius(null));
    }

    #[Test]
    public function clampRadiusFloorsBelowMinimum(): void
    {
        self::assertSame(InRidePoiCategory::MIN_RADIUS_METERS, InRidePoiCategory::FOOD->clampRadius(10));
    }

    #[Test]
    public function clampRadiusCapsAboveMaximum(): void
    {
        self::assertSame(InRidePoiCategory::MAX_RADIUS_METERS, InRidePoiCategory::MECHANIC->clampRadius(50_000));
    }

    #[Test]
    public function clampRadiusKeepsAnInRangeValue(): void
    {
        self::assertSame(7_500, InRidePoiCategory::HEALTH->clampRadius(7_500));
    }

    #[Test]
    #[DataProvider('requiresNameProvider')]
    public function requiresNameOnlyForUnnamedUnactionableBuckets(InRidePoiCategory $category, bool $expected): void
    {
        self::assertSame($expected, $category->requiresName());
    }

    /**
     * @return iterable<string, array{InRidePoiCategory, bool}>
     */
    public static function requiresNameProvider(): iterable
    {
        yield 'food' => [InRidePoiCategory::FOOD, true];
        yield 'mechanic' => [InRidePoiCategory::MECHANIC, true];
        yield 'health' => [InRidePoiCategory::HEALTH, true];
        yield 'train' => [InRidePoiCategory::TRAIN, true];
        yield 'water' => [InRidePoiCategory::WATER, false];
        yield 'shelter' => [InRidePoiCategory::SHELTER, false];
        yield 'resupply' => [InRidePoiCategory::RESUPPLY, false];
        yield 'charging' => [InRidePoiCategory::CHARGING, false];
    }

    #[Test]
    #[DataProvider('candidateLimitProvider')]
    public function candidateLimitIs200WhereAPhpHoursFilterRemains(InRidePoiCategory $category, int $expected): void
    {
        self::assertSame($expected, $category->candidateLimit());
    }

    /**
     * @return iterable<string, array{InRidePoiCategory, int}>
     */
    public static function candidateLimitProvider(): iterable
    {
        yield 'food' => [InRidePoiCategory::FOOD, 200];
        yield 'resupply' => [InRidePoiCategory::RESUPPLY, 200];
        yield 'mechanic' => [InRidePoiCategory::MECHANIC, 200];
        yield 'health' => [InRidePoiCategory::HEALTH, 200];
        yield 'water' => [InRidePoiCategory::WATER, 50];
        yield 'shelter' => [InRidePoiCategory::SHELTER, 50];
        yield 'train' => [InRidePoiCategory::TRAIN, 50];
        yield 'charging' => [InRidePoiCategory::CHARGING, 50];
    }
}
