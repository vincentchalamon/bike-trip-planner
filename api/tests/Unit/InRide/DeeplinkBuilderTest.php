<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\Geo\GeoPoint;
use App\InRide\DeeplinkBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeeplinkBuilderTest extends TestCase
{
    private DeeplinkBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new DeeplinkBuilder();
    }

    #[Test]
    public function googleMapsLinkIsBicycling(): void
    {
        $url = $this->builder->googleMapsBicycling(
            new GeoPoint(50.8503, 4.3517),
            new GeoPoint(50.8500, 4.3525),
        );

        $this->assertStringStartsWith('https://www.google.com/maps/dir/?api=1', $url);
        $this->assertStringContainsString('travelmode=bicycling', $url);
        $this->assertStringContainsString('origin=50.8503,4.3517', $url);
        $this->assertStringContainsString('destination=50.85,4.3525', $url);
    }

    #[Test]
    public function googleMapsTrimsTrailingZeros(): void
    {
        $url = $this->builder->googleMapsBicycling(
            new GeoPoint(50.0, 4.0),
            new GeoPoint(50.1234500, 4.0000000),
        );

        $this->assertStringContainsString('origin=50,4', $url);
        $this->assertStringContainsString('destination=50.12345,4', $url);
    }

    #[Test]
    public function googleMapsAcceptsNegativeCoordinates(): void
    {
        $url = $this->builder->googleMapsBicycling(
            new GeoPoint(-33.8688, 151.2093),
            new GeoPoint(-33.8700, 151.2100),
        );

        $this->assertStringContainsString('origin=-33.8688,151.2093', $url);
    }

    #[Test]
    public function openStreetMapUsesBikeEngine(): void
    {
        $url = $this->builder->openStreetMap(
            new GeoPoint(48.8566, 2.3522),
            new GeoPoint(48.8606, 2.3376),
        );

        $this->assertStringStartsWith('https://www.openstreetmap.org/directions', $url);
        $this->assertStringContainsString('engine=fossgis_osrm_bike', $url);
        // Coordinates are url-encoded: "lat,lon;lat,lon" → "%2C" between lat/lon and "%3B" between origin/destination.
        $this->assertStringContainsString('route=48.8566%2C2.3522%3B48.8606%2C2.3376', $url);
    }

    #[Test]
    public function precisionIsAtMostSevenDecimals(): void
    {
        $url = $this->builder->googleMapsBicycling(
            new GeoPoint(50.85031234567890, 4.35171234567890),
            new GeoPoint(0.0, 0.0),
        );

        $this->assertStringContainsString('origin=50.8503123,4.3517123', $url);
    }
}
