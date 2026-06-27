<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\ReverseGeocoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReverseGeocoderTest extends TestCase
{
    #[Test]
    public function prefersTheCityNameOverTheRawPoiName(): void
    {
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"name":"Gare de Lyon-Part-Dieu","address":{"city":"Lyon"}}')),
            $this->cache(),
        );

        self::assertSame('Lyon', $geocoder->cityName(45.76, 4.84));
    }

    #[Test]
    public function fallsBackThroughTownVillageHamletMunicipality(): void
    {
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"name":"POI","address":{"village":"Saint-Bonnet-en-Champsaur"}}')),
            $this->cache(),
        );

        self::assertSame('Saint-Bonnet-en-Champsaur', $geocoder->cityName(44.67, 6.07));
    }

    #[Test]
    public function fallsBackToTheRawNameWhenAddressHasNoLocality(): void
    {
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"name":"Col du Galibier","address":{}}')),
            $this->cache(),
        );

        self::assertSame('Col du Galibier', $geocoder->cityName(45.06, 6.41));
    }

    #[Test]
    public function returnsNullWhenNominatimReportsAnError(): void
    {
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"error":"Unable to geocode"}')),
            $this->cache(),
        );

        self::assertNull($geocoder->cityName(0.0, 0.0));
    }

    #[Test]
    public function returnsNullOnTransportError(): void
    {
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
            $this->cache(),
        );

        self::assertNull($geocoder->cityName(45.0, 4.0));
    }

    #[Test]
    public function cachesTheResultAcrossCalls(): void
    {
        // Only one response queued: a second HTTP call would throw, so the second
        // lookup of the same coordinate must be served from cache.
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"address":{"city":"Lille"}}')),
            $this->cache(),
        );

        self::assertSame('Lille', $geocoder->cityName(50.6365, 3.0635));
        self::assertSame('Lille', $geocoder->cityName(50.6365, 3.0635));
    }

    private function cache(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }
}
