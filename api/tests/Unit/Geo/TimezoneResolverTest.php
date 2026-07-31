<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\HaversineDistance;
use App\Geo\TimezoneResolver;
use App\Osm\AdminBoundaryRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimezoneResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('frenchCities')]
    public function resolvesEveryFrenchCityToParis(float $lat, float $lon): void
    {
        // A plain nearest-city match over all identifiers puts Nantes on Europe/Jersey
        // (UTC+0) and Marseille on Europe/Monaco: narrowing by country avoids that.
        $resolver = $this->resolver('FR');

        $this->assertSame('Europe/Paris', $resolver->resolve($lat, $lon)->getName());
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function frenchCities(): iterable
    {
        yield 'Paris' => [48.85, 2.35];
        yield 'Nantes' => [47.22, -1.55];
        yield 'Marseille' => [43.30, 5.37];
        yield 'Perpignan' => [42.70, 2.90];
    }

    #[Test]
    public function picksTheNearestZoneWhenTheCountryHasSeveral(): void
    {
        $resolver = $this->resolver('ES');

        // Spain owns Europe/Madrid, Africa/Ceuta and Atlantic/Canary.
        $this->assertSame('Europe/Madrid', $resolver->resolve(40.42, -3.70)->getName());
        $this->assertSame('Atlantic/Canary', $resolver->resolve(28.10, -15.41)->getName());
    }

    #[Test]
    public function fallsBackToTheNearestZoneWorldwideWhenNoCountryResolves(): void
    {
        // admin_level=2 boundaries may be missing from a regional OSM extract: the
        // resolution degrades to a worldwide nearest match, never to UTC-by-default.
        $resolver = $this->resolver(null);

        $this->assertSame('Europe/Paris', $resolver->resolve(48.85, 2.35)->getName());
    }

    #[Test]
    public function unknownCountryCodeStillYieldsAZone(): void
    {
        $resolver = $this->resolver('ZZ');

        $this->assertSame('Europe/Paris', $resolver->resolve(48.85, 2.35)->getName());
    }

    private function resolver(?string $countryCode): TimezoneResolver
    {
        $adminBoundaryRepository = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $adminBoundaryRepository->method('findCountryCodeAt')->willReturn($countryCode);

        return new TimezoneResolver(new HaversineDistance(), $adminBoundaryRepository);
    }
}
