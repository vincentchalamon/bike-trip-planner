<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\Geo\HaversineDistance;
use App\Geo\NearbyNameDeduplicator;
use App\Poi\PoiSourceInterface;
use App\Poi\PoiSourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoiSourceRegistryTest extends TestCase
{
    /**
     * @return list<array{lat: float, lon: float}>
     */
    private function route(): array
    {
        return [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.5, 'lon' => 2.5]];
    }

    /**
     * @param list<array{name: string, category: string, lat: float, lon: float, wikidataId: string|null, source: string}> $pois
     */
    private function source(array $pois): PoiSourceInterface
    {
        return new class($pois) implements PoiSourceInterface {
            /** @param list<array{name: string, category: string, lat: float, lon: float, wikidataId: string|null, source: string}> $pois */
            public function __construct(private array $pois)
            {
            }

            public function fetchInCorridor(array $route, int $radiusMeters): array
            {
                return $this->pois;
            }
        };
    }

    /**
     * @param list<PoiSourceInterface> $sources
     */
    private function registry(array $sources): PoiSourceRegistry
    {
        return new PoiSourceRegistry($sources, new NearbyNameDeduplicator(new HaversineDistance()));
    }

    #[Test]
    public function mergesPoisFromEverySource(): void
    {
        $osm = $this->source([
            ['name' => 'Boulangerie A', 'category' => 'bakery', 'lat' => 48.10, 'lon' => 2.10, 'wikidataId' => null, 'source' => 'osm'],
        ]);
        $datatourisme = $this->source([
            ['name' => 'Restaurant B', 'category' => 'restaurant', 'lat' => 48.20, 'lon' => 2.20, 'wikidataId' => null, 'source' => 'datatourisme'],
        ]);

        $result = $this->registry([$osm, $datatourisme])->fetchAllInCorridor($this->route(), 2000);

        self::assertCount(2, $result);
    }

    #[Test]
    public function collapsesSameNamedNearbyPoisPreferringDataTourisme(): void
    {
        // Same normalized name within 75 m from two sources → one entry, the
        // curated DataTourisme one wins.
        $osm = $this->source([
            ['name' => 'Boulangerie du Centre', 'category' => 'bakery', 'lat' => 48.1000, 'lon' => 2.1000, 'wikidataId' => null, 'source' => 'osm'],
        ]);
        $datatourisme = $this->source([
            ['name' => 'Boulangerie du Centre', 'category' => 'bakery', 'lat' => 48.1001, 'lon' => 2.1001, 'wikidataId' => null, 'source' => 'datatourisme'],
        ]);

        $result = $this->registry([$osm, $datatourisme])->fetchAllInCorridor($this->route(), 2000);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
    }

    #[Test]
    public function keepsDistinctNearbyPois(): void
    {
        // Different names at the same spot are distinct businesses, both kept.
        $osm = $this->source([
            ['name' => 'Boulangerie du Centre', 'category' => 'bakery', 'lat' => 48.10, 'lon' => 2.10, 'wikidataId' => null, 'source' => 'osm'],
            ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.10, 'lon' => 2.10, 'wikidataId' => null, 'source' => 'osm'],
        ]);

        $result = $this->registry([$osm])->fetchAllInCorridor($this->route(), 2000);

        self::assertCount(2, $result);
    }

    #[Test]
    public function emptySourcesReturnEmptyArray(): void
    {
        self::assertSame([], $this->registry([])->fetchAllInCorridor($this->route(), 2000));
    }
}
