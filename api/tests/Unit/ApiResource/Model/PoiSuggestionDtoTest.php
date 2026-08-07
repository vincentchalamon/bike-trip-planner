<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Model;

use App\ApiResource\Model\PoiSuggestionDto;
use App\InRide\InRidePoiCategory;
use App\InRide\PoiSuggestion;
use App\InRide\PoiWarning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoiSuggestionDtoTest extends TestCase
{
    #[Test]
    public function roundsDistanceAndDetourAndFormatsClosingTime(): void
    {
        $dto = PoiSuggestionDto::fromSuggestion(new PoiSuggestion(
            name: 'Fontaine',
            category: InRidePoiCategory::WATER,
            osmType: 'node',
            osmId: 42,
            lat: 48.1,
            lon: 2.3,
            distanceMeters: 123.6,
            detourMeters: 87.4,
            openingHoursToday: '08:00-15:00',
            closesAt: new \DateTimeImmutable('2026-08-06T15:00:00+00:00'),
            phone: '+33123456789',
            deeplink: 'https://www.google.com/maps/dir/?api=1&destination=48.1,2.3&travelmode=bicycling',
            warning: PoiWarning::CLOSES_SOON,
            warningMinutes: 20,
        ));

        self::assertSame(124, $dto->distance_m);
        self::assertSame(87, $dto->detour_m);
        self::assertSame('2026-08-06T15:00:00+00:00', $dto->closes_at);
        self::assertSame(PoiWarning::CLOSES_SOON, $dto->warning);
        self::assertSame(20, $dto->warning_minutes);
        self::assertSame('08:00-15:00', $dto->opening_hours_today);
    }

    #[Test]
    public function keepsAnUnknownDetourAsNullRatherThanZero(): void
    {
        $dto = PoiSuggestionDto::fromSuggestion(new PoiSuggestion(
            name: 'Abri',
            category: InRidePoiCategory::SHELTER,
            osmType: 'way',
            osmId: 7,
            lat: 48.0,
            lon: 2.0,
            distanceMeters: 40.2,
            detourMeters: null,
            openingHoursToday: null,
            closesAt: null,
            phone: null,
            deeplink: 'https://www.google.com/maps/dir/?api=1&destination=48,2&travelmode=bicycling',
            warning: PoiWarning::HOURS_UNVERIFIED,
        ));

        self::assertNull($dto->detour_m);
        self::assertNull($dto->closes_at);
        self::assertNull($dto->warning_minutes);
        self::assertSame(40, $dto->distance_m);
        self::assertSame(PoiWarning::HOURS_UNVERIFIED, $dto->warning);
    }
}
