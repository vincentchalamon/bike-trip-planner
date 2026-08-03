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
     * The dedupe pass reads name/coordinates/wikidataId/source only, so the fixtures
     * leave the enrichment columns of the source shape defaulted.
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, wikidataId: string|null, source: string, osmType?: string|null, osmId?: int|null, openingHours?: string|null, website?: string|null}> $pois
     */
    private function source(array $pois): PoiSourceInterface
    {
        $pois = array_map(
            static fn (array $poi): array => $poi + ['openingHours' => null, 'website' => null],
            $pois,
        );

        return new readonly class ($pois) implements PoiSourceInterface {
            /** @param list<array{name: string|null, category: string, lat: float, lon: float, wikidataId: string|null, source: string, osmType?: string|null, osmId?: int|null, openingHours?: string|null, website?: string|null}> $pois */
            public function __construct(private array $pois)
            {
            }

            public function fetchInCorridor(array $route, int $radiusMeters): array
            {
                // Fixtures state only what a case is about; the OSM identity defaults
                // to "not an OSM entry", which is what a curated source looks like.
                return array_map(static fn (array $p): array => $p + ['osmType' => null, 'osmId' => null], $this->pois);
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
    public function keepsEveryAnonymousResupplyPoiWithinProximity(): void
    {
        // Three nameless cafes in the same village centre, all within 75 m of one
        // another: the resupply count must not shrink because they share a
        // category (issue #874).
        $osm = $this->source([
            ['name' => null, 'category' => 'cafe', 'lat' => 48.1000, 'lon' => 2.1000, 'wikidataId' => null, 'source' => 'osm'],
            ['name' => null, 'category' => 'cafe', 'lat' => 48.1003, 'lon' => 2.1000, 'wikidataId' => null, 'source' => 'osm'],
            ['name' => null, 'category' => 'cafe', 'lat' => 48.1005, 'lon' => 2.1000, 'wikidataId' => null, 'source' => 'osm'],
        ]);

        $result = $this->registry([$osm])->fetchAllInCorridor($this->route(), 2000);

        self::assertCount(3, $result);
    }

    #[Test]
    public function emptySourcesReturnEmptyArray(): void
    {
        self::assertSame([], $this->registry([])->fetchAllInCorridor($this->route(), 2000));
    }
}
