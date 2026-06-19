<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\Geocoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeocoderTest extends TestCase
{
    #[Test]
    public function resolvesTheFirstNominatimResult(): void
    {
        $geocoder = new Geocoder(
            new MockHttpClient(new MockResponse('[{"lat":"50.6365","lon":"3.0635","display_name":"Lille"}]')),
            $this->cache(),
        );

        $coordinate = $geocoder->geocode('Lille');

        self::assertNotNull($coordinate);
        self::assertEqualsWithDelta(50.6365, $coordinate->lat, 0.0001);
        self::assertEqualsWithDelta(3.0635, $coordinate->lon, 0.0001);
    }

    #[Test]
    public function returnsNullForABlankPlaceWithoutCallingNominatim(): void
    {
        // MockHttpClient with no queued responses: any HTTP call would throw.
        $geocoder = new Geocoder(new MockHttpClient([]), $this->cache());

        self::assertNull($geocoder->geocode('   '));
    }

    #[Test]
    public function returnsNullWhenNominatimHasNoMatch(): void
    {
        $geocoder = new Geocoder(new MockHttpClient(new MockResponse('[]')), $this->cache());

        self::assertNull($geocoder->geocode('Atlantis-sur-Mer'));
    }

    #[Test]
    public function cachesTheResultAcrossCalls(): void
    {
        // Only one response is queued: a second HTTP call would throw, so the
        // second geocode must be served from cache.
        $geocoder = new Geocoder(
            new MockHttpClient(new MockResponse('[{"lat":"50.6","lon":"3.0"}]')),
            $this->cache(),
        );

        $first = $geocoder->geocode('Lille');
        $second = $geocoder->geocode('Lille');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->lat, $second->lat);
    }

    #[Test]
    public function cachesNullForAnUnresolvablePlace(): void
    {
        // Only one response queued: a second HTTP call would throw, so a cached
        // null miss is what lets the second call succeed.
        $geocoder = new Geocoder(new MockHttpClient(new MockResponse('[]')), $this->cache());

        $first = $geocoder->geocode('Atlantis-sur-Mer');
        $second = $geocoder->geocode('Atlantis-sur-Mer');

        self::assertNull($first);
        self::assertNull($second);
    }

    private function cache(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }
}
