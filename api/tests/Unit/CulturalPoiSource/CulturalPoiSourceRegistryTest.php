<?php

declare(strict_types=1);

namespace App\Tests\Unit\CulturalPoiSource;

use App\CulturalPoiSource\CulturalPoiSourceInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use App\Geo\GeoDistanceInterface;
use App\Geo\NearbyNameDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CulturalPoiSourceRegistryTest extends TestCase
{
    private NearbyNameDeduplicator $deduplicator;

    protected function setUp(): void
    {
        // A large constant distance so only the wikidata key dedups here; the
        // proximity+name pass is exercised in NearbyNameDeduplicatorTest.
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(100_000.0);
        $this->deduplicator = new NearbyNameDeduplicator($haversine);
    }

    /**
     * @return list<list<array{lat: float, lon: float}>>
     */
    private function stageGeometries(): array
    {
        return [
            [['lat' => 48.0, 'lon' => 2.0], ['lat' => 48.5, 'lon' => 2.5]],
        ];
    }

    /**
     * @param list<CulturalPoiSourceInterface> $sources
     */
    private function registry(array $sources): CulturalPoiSourceRegistry
    {
        return new CulturalPoiSourceRegistry($sources, $this->deduplicator);
    }

    #[Test]
    public function fetchAllForStagesMergesResultsFromEnabledSources(): void
    {
        $sourceA = $this->createStub(CulturalPoiSourceInterface::class);
        $sourceA->method('isEnabled')->willReturn(true);
        $sourceA->method('fetchForStages')->willReturn([
            ['name' => 'Museum A', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm', 'wikidataId' => null, 'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $sourceB = $this->createStub(CulturalPoiSourceInterface::class);
        $sourceB->method('isEnabled')->willReturn(true);
        $sourceB->method('fetchForStages')->willReturn([
            ['name' => 'Museum B', 'type' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'source' => 'datatourisme', 'wikidataId' => null, 'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $result = $this->registry([$sourceA, $sourceB])->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(2, $result);
        self::assertSame('Museum A', $result[0]['name']);
        self::assertSame('Museum B', $result[1]['name']);
    }

    #[Test]
    public function disabledSourceIsSkipped(): void
    {
        $enabled = $this->createStub(CulturalPoiSourceInterface::class);
        $enabled->method('isEnabled')->willReturn(true);
        $enabled->method('fetchForStages')->willReturn([
            ['name' => 'Active POI', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm', 'wikidataId' => null, 'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $disabled = $this->createMock(CulturalPoiSourceInterface::class);
        $disabled->method('isEnabled')->willReturn(false);
        $disabled->expects($this->never())->method('fetchForStages');

        $result = $this->registry([$enabled, $disabled])->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
    }

    #[Test]
    public function emptySourcesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->registry([])->fetchAllForStages($this->stageGeometries(), 500));
    }

    #[Test]
    public function radiusIsForwardedToSources(): void
    {
        $source = $this->createMock(CulturalPoiSourceInterface::class);
        $source->method('isEnabled')->willReturn(true);
        $source->expects($this->once())
            ->method('fetchForStages')
            ->with($this->stageGeometries(), 1000)
            ->willReturn([]);

        $this->registry([$source])->fetchAllForStages($this->stageGeometries(), 1000);
    }

    #[Test]
    public function datatourismeOverridesOsmWhenSameWikidataId(): void
    {
        $osm = $this->createStub(CulturalPoiSourceInterface::class);
        $osm->method('isEnabled')->willReturn(true);
        $osm->method('fetchForStages')->willReturn([
            ['name' => 'Louvre (OSM)', 'type' => 'museum', 'lat' => 48.86, 'lon' => 2.33,
                'source' => 'osm', 'wikidataId' => 'Q19675',
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $dt = $this->createStub(CulturalPoiSourceInterface::class);
        $dt->method('isEnabled')->willReturn(true);
        $dt->method('fetchForStages')->willReturn([
            ['name' => 'Louvre (DT)', 'type' => 'museum', 'lat' => 48.86, 'lon' => 2.33,
                'source' => 'datatourisme', 'wikidataId' => 'Q19675',
                'openingHours' => 'Mon–Sun 09:00–18:00', 'estimatedPrice' => 17.0, 'description' => null],
        ]);

        $result = $this->registry([$osm, $dt])->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
        self::assertSame('Louvre (DT)', $result[0]['name']);
    }

    #[Test]
    public function poisWithoutWikidataIdAreAllKept(): void
    {
        $source = $this->createStub(CulturalPoiSourceInterface::class);
        $source->method('isEnabled')->willReturn(true);
        $source->method('fetchForStages')->willReturn([
            ['name' => 'POI A', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm', 'wikidataId' => null,
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
            ['name' => 'POI B', 'type' => 'monument', 'lat' => 48.2, 'lon' => 2.2, 'source' => 'osm', 'wikidataId' => null,
                'openingHours' => null, 'estimatedPrice' => null, 'description' => null],
        ]);

        $result = $this->registry([$source])->fetchAllForStages($this->stageGeometries(), 500);

        self::assertCount(2, $result);
    }
}
