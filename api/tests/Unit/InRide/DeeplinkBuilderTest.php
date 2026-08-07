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
    public function googleMapsLinkIsBicyclingToTheDestinationOnly(): void
    {
        $url = $this->builder->googleMapsBicycling(new GeoPoint(50.8500, 4.3525));

        $this->assertStringStartsWith('https://www.google.com/maps/dir/?api=1', $url);
        $this->assertStringContainsString('travelmode=bicycling', $url);
        $this->assertStringContainsString('destination=50.85,4.3525', $url);
        // The rider's live position must never leak into the URL.
        $this->assertStringNotContainsString('origin=', $url);
    }

    #[Test]
    public function googleMapsTrimsTrailingZeros(): void
    {
        $url = $this->builder->googleMapsBicycling(new GeoPoint(50.1234500, 4.0000000));

        $this->assertStringContainsString('destination=50.12345,4', $url);
    }

    #[Test]
    public function googleMapsAcceptsNegativeCoordinates(): void
    {
        $url = $this->builder->googleMapsBicycling(new GeoPoint(-33.8700, 151.2100));

        $this->assertStringContainsString('destination=-33.87,151.21', $url);
    }

    #[Test]
    public function precisionIsAtMostSevenDecimals(): void
    {
        $url = $this->builder->googleMapsBicycling(new GeoPoint(50.85031234567890, 4.35171234567890));

        $this->assertStringContainsString('destination=50.8503123,4.3517123', $url);
    }
}
